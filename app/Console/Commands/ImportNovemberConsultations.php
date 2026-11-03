<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Support\GreenMudaRoster;
use App\Support\PendingConfirmation;
use App\Support\PhoneNumber;
use App\Support\WilayahNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportNovemberConsultations extends Command
{
    protected $signature = 'app:import-november-leads
        {--file= : Path JSON data (default: database/seeders/data/november_2026_consultations.json)}
        {--apply : Tulis perubahan ke database (default dry-run)}';

    protected $description = 'Import 1.632 data konsultasi dan 254 sequence nomor urut dengan mempertahankan ID Konsumen dan ID Akun secara eksak';

    private const LEGACY_CATEGORY_ALIASES = [
        'almunium' => 'alumunium',
        'aluminium' => 'alumunium',
        'apartement' => 'interior apartemen',
        'apartment' => 'interior apartemen',
        'box' => 'box elektronik',
        'jasa design' => 'jasa desain',
        'kantor' => 'interior kantor',
        'renovasi rumah' => 'renovasi interior',
        'renovasi' => 'renovasi interior',
        'toko' => 'interior toko',
        'kitchen set' => 'kitchenset',
        'rak buku' => 'rak',
        'interior full' => 'full home',
        'lain-lain' => 'lain-lain',
    ];

    public function handle(): int
    {
        $filePath = $this->option('file') ?: database_path('seeders/data/november_2026_consultations.json');
        if (! is_file($filePath)) {
            $this->error("File JSON tidak ditemukan: {$filePath}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($filePath), true);
        if (! is_array($payload) || ! isset($payload['leads'], $payload['sequences'])) {
            $this->error('Format JSON tidak valid.');

            return self::FAILURE;
        }

        $leads = $payload['leads'];
        $sequences = $payload['sequences'];
        $apply = (bool) $this->option('apply');

        // 1. Pastikan seluruh 31 akun di DB memiliki ID eksak sesuai GreenMudaRoster::ACCOUNT_IDS
        $accountsById = Account::query()->get(['id', 'name', 'account_group', 'account_group_id'])->keyBy('id');
        foreach (GreenMudaRoster::ACCOUNT_IDS as $expectedName => $expectedId) {
            $acc = $accountsById->get($expectedId);
            if (! $acc) {
                throw new RuntimeException("Akun '{$expectedName}' dengan ID {$expectedId} belum ada di tabel accounts. Jalankan php artisan app:sync-green-muda-roster --apply terlebih dahulu.");
            }
        }

        // 2. Siapkan peta NeedsCategory & StatusCategory
        $needsCategoryMap = NeedsCategory::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (NeedsCategory $c) => [$this->normalizeLookup($c->name) => (int) $c->id])
            ->all();

        foreach (self::LEGACY_CATEGORY_ALIASES as $alias => $canonical) {
            $aliasKey = $this->normalizeLookup($alias);
            $canonicalKey = $this->normalizeLookup($canonical);
            if (! isset($needsCategoryMap[$aliasKey]) && isset($needsCategoryMap[$canonicalKey])) {
                $needsCategoryMap[$aliasKey] = $needsCategoryMap[$canonicalKey];
            }
        }

        $statusCategoryMap = StatusCategory::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (StatusCategory $s) => [$this->normalizeLookup($s->name) => (int) $s->id])
            ->all();

        // 3. Siapkan peta creator (admin pemegang akun, fallback ke super_admin)
        $superAdminId = (int) (User::query()->where('role', UserRole::SuperAdmin->value)->value('id') ?? 1);
        $adminByAccountId = [];
        $pivots = DB::table('account_user')
            ->join('users', 'users.id', '=', 'account_user.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.role', UserRole::Admin->value)
            ->select(['account_user.account_id', 'account_user.user_id'])
            ->get();
        foreach ($pivots as $pivot) {
            $adminByAccountId[(int) $pivot->account_id] = (int) $pivot->user_id;
        }

        // 4. Validasi seluruh 1.619 baris terlebih dahulu (Zero Hallucination Check)
        $preparedRows = [];
        $pivotEntriesByConsultationId = [];

        foreach ($leads as $index => $row) {
            $rowNo = $row['no'] ?? ($index + 1);
            $consultationId = trim((string) ($row['consultation_id'] ?? ''));
            $accountId = (int) ($row['account_id'] ?? 0);

            if (! preg_match('/^(\d{2})\.(\d{4})\.(\d{4})$/', $consultationId, $matches)) {
                throw new RuntimeException("Baris #{$rowNo}: Format ID Konsumen '{$consultationId}' tidak valid.");
            }

            $prefixAccountId = (int) $matches[1];
            if ($prefixAccountId !== $accountId) {
                throw new RuntimeException("Baris #{$rowNo}: Prefix ID Konsumen '{$consultationId}' ({$prefixAccountId}) tidak cocok dengan ID Akun ({$accountId}).");
            }

            $account = $accountsById->get($accountId);
            if (! $account) {
                throw new RuntimeException("Baris #{$rowNo}: ID Akun {$accountId} tidak ditemukan di database.");
            }

            // Validasi & petakan Jenis Kebutuhan
            $needsRaw = trim((string) ($row['needs_raw'] ?? ''));
            $categoryIds = [];
            foreach (explode(',', $needsRaw) as $part) {
                $lookup = $this->normalizeLookup($part);
                if ($lookup === '') {
                    continue;
                }
                if (! isset($needsCategoryMap[$lookup])) {
                    throw new RuntimeException("Baris #{$rowNo} ({$consultationId}): Jenis Kebutuhan '{$part}' tidak ditemukan di master needs_categories.");
                }
                $categoryIds[] = $needsCategoryMap[$lookup];
            }
            $categoryIds = array_values(array_unique($categoryIds));
            if ($categoryIds === []) {
                throw new RuntimeException("Baris #{$rowNo} ({$consultationId}): Jenis Kebutuhan kosong.");
            }

            // Validasi & petakan Status Pipeline (kategori)
            $statusRaw = trim((string) ($row['status_raw'] ?? ''));
            $statusLookup = $this->normalizeLookup($statusRaw);
            if (! isset($statusCategoryMap[$statusLookup])) {
                throw new RuntimeException("Baris #{$rowNo} ({$consultationId}): Status '{$statusRaw}' tidak ditemukan di master status_categories.");
            }
            $statusId = $statusCategoryMap[$statusLookup];

            // Normalisasi nomor telepon & wilayah
            $phoneRaw = trim((string) ($row['phone_raw'] ?? ''));
            $phone = PhoneNumber::toE164($phoneRaw) ?? $phoneRaw;
            $phoneNormalized = Consultation::normalizeLeadPhone($phone);

            $rawProvince = $row['province'] ?? null;
            $rawCity = $row['city'] ?? null;
            $rawDistrict = $row['district'] ?? null;

            $province = WilayahNormalizer::canonicalProvince($rawProvince) ?? ($rawProvince !== '' ? $rawProvince : null);
            $city = WilayahNormalizer::canonicalCity($rawCity) ?? ($rawCity !== '' ? $rawCity : null);
            $district = null;
            if ($rawDistrict !== null && trim((string) $rawDistrict) !== '') {
                $resolved = WilayahNormalizer::canonicalDistrict((string) $rawDistrict, $city);
                $district = $resolved['value'];
            }

            $consultationDate = $row['consultation_date'];
            $updatedAt = $row['updated_at'] ?: ($consultationDate . ' 08:00:00');
            $createdAt = $consultationDate . ' 08:00:00';
            if ($updatedAt < $createdAt) {
                $createdAt = $updatedAt;
            }

            $preparedRows[] = [
                'consultation_id' => $consultationId,
                'client_name' => $row['client_name'],
                'phone' => $phone,
                'phone_normalized' => $phoneNormalized,
                'emergency_phone' => null,
                'emergency_phone_normalized' => '',
                'province' => PendingConfirmation::normalizeRegion($province),
                'city' => PendingConfirmation::normalizeRegion($city),
                'district' => PendingConfirmation::normalizeRegion($district),
                'address' => null,
                'account_id' => $accountId,
                'account_group' => $account->account_group,
                'account_group_id' => $account->account_group_id,
                'needs_category_id' => $categoryIds[0],
                'product_details' => $row['product_details'],
                'status_category_id' => $statusId,
                'notes' => $row['notes'],
                'created_by' => $adminByAccountId[$accountId] ?? $superAdminId,
                'consultation_date' => $consultationDate,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
                'deleted_at' => null,
            ];

            $pivotEntriesByConsultationId[$consultationId] = [
                'category_ids' => $categoryIds,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
        }

        $this->info(($apply ? '[APPLY]' : '[DRY-RUN]') . ' Validasi 100% lulus:');
        $this->line('  - Total Konsultasi Siap Import : ' . count($preparedRows));
        $this->line('  - Total Sequence Siap Sync     : ' . count($sequences));

        if (! $apply) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($preparedRows, $pivotEntriesByConsultationId, $sequences) {
            // Upsert consultations dalam chunk 250 baris
            foreach (array_chunk($preparedRows, 250) as $chunk) {
                DB::table('consultations')->upsert(
                    $chunk,
                    ['consultation_id'],
                    [
                        'client_name',
                        'phone',
                        'phone_normalized',
                        'province',
                        'city',
                        'district',
                        'address',
                        'account_id',
                        'account_group',
                        'account_group_id',
                        'needs_category_id',
                        'product_details',
                        'status_category_id',
                        'notes',
                        'created_by',
                        'consultation_date',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ]
                );
            }

            // Sinkronkan tabel pivot consultation_needs_category
            $idMap = DB::table('consultations')
                ->whereIn('consultation_id', array_keys($pivotEntriesByConsultationId))
                ->pluck('id', 'consultation_id')
                ->all();

            DB::table('consultation_needs_category')
                ->whereIn('consultation_id', array_values($idMap))
                ->delete();

            $pivotRows = [];
            foreach ($pivotEntriesByConsultationId as $cid => $meta) {
                $dbId = $idMap[$cid] ?? null;
                if (! $dbId) {
                    continue;
                }
                foreach ($meta['category_ids'] as $catId) {
                    $pivotRows[] = [
                        'consultation_id' => $dbId,
                        'needs_category_id' => $catId,
                        'created_at' => $meta['created_at'],
                        'updated_at' => $meta['updated_at'],
                    ];
                }
            }

            foreach (array_chunk($pivotRows, 500) as $pivotChunk) {
                DB::table('consultation_needs_category')->insert($pivotChunk);
            }

            // Sinkronkan consultation_sequences dari Opsi + nilai maksimum aktual di consultations
            $sequenceMap = [];
            foreach ($sequences as $seq) {
                $accId = (int) $seq['account_id'];
                $ym = (string) $seq['year_month'];
                $lastNum = (int) $seq['last_number'];
                $key = "{$accId}|{$ym}";
                $sequenceMap[$key] = max($sequenceMap[$key] ?? 0, $lastNum);
            }

            foreach ($preparedRows as $r) {
                if (preg_match('/^\d{2}\.(\d{4})\.(\d{4})$/', $r['consultation_id'], $m)) {
                    $accId = (int) $r['account_id'];
                    $ym = $m[1];
                    $num = (int) $m[2];
                    $key = "{$accId}|{$ym}";
                    $sequenceMap[$key] = max($sequenceMap[$key] ?? 0, $num);
                }
            }

            $now = now();
            foreach ($sequenceMap as $key => $lastNum) {
                [$accIdStr, $ym] = explode('|', $key);
                $accId = (int) $accIdStr;

                $existing = DB::table('consultation_sequences')
                    ->where('account_id', $accId)
                    ->where('year_month', $ym)
                    ->first();

                if ($existing) {
                    if ((int) $existing->last_number < $lastNum) {
                        DB::table('consultation_sequences')
                            ->where('id', $existing->id)
                            ->update([
                                'last_number' => $lastNum,
                                'updated_at' => $now,
                            ]);
                    }
                } else {
                    DB::table('consultation_sequences')->insert([
                        'account_id' => $accId,
                        'year_month' => $ym,
                        'last_number' => $lastNum,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        Cache::flush();

        $this->info('Berhasil mengimpor ' . count($preparedRows) . ' data konsultasi dan menyinkronkan ' . count($sequences) . ' sequence nomor urut.');

        return self::SUCCESS;
    }

    private function normalizeLookup(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }
}
