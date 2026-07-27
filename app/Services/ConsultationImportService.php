<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Services\NotificationSummaryService;
use App\Services\WebPushService;
use App\Support\PendingConfirmation;
use App\Support\PhoneNumber;
use App\Support\WilayahNormalizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessConsultationImportJob;
use RuntimeException;
use SplFileObject;
use Throwable;

class ConsultationImportService
{
    private const CHUNK_SIZE = 500;

    public function queue(UploadedFile $file, User $user): ConsultationImport
    {
        $storedPath = $file->store('imports/consultations');

        $import = ConsultationImport::create([
            'user_id' => $user->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => 'queued',
        ]);

        // Dispatch to queue worker instead of processing synchronously
        ProcessConsultationImportJob::dispatch($import->id);

        return $import;
    }

    public function process(ConsultationImport $import): void
    {
        $import->refresh();
        $import->loadMissing('user');

        if ($import->status === 'completed') {
            return;
        }

        if ($import->status === 'processing' && $import->started_at) {
            return;
        }

        $import->update([
            'status' => 'processing',
            'started_at' => now(),
            'error_preview' => null,
            'total_rows' => 0,
            'success_count' => 0,
            'duplicate_count' => 0,
            'updated_count' => 0,
            'error_count' => 0,
        ]);

        try {
            [$defaultStatus, $defaultCategory] = $this->resolveDefaults();
            $accounts = Account::query()->get(['id', 'name']);
            $validAccountIds = $accounts->pluck('id')->all();
            $accountNameMap = $accounts
                ->mapWithKeys(fn (Account $account) => [$this->normalizeLookup($account->name) => $account->id])
                ->all();
            $needsCategoryMap = NeedsCategory::query()
                ->get(['id', 'name'])
                ->mapWithKeys(fn (NeedsCategory $category) => [$this->normalizeLookup($category->name) => $category->id])
                ->all();

            // File Excel lama masih menuliskan "Belum ada konfirmasi" pada kolom
            // Jenis Kebutuhan. Petakan ke kategori default yang sudah di-rename
            // supaya baris tersebut tidak jatuh ke kategori acak.
            $legacyPendingKey = $this->normalizeLookup(NeedsCategory::PENDING_LEGACY_LABEL);
            $currentPendingKey = $this->normalizeLookup(NeedsCategory::PENDING_LABEL);
            if (! isset($needsCategoryMap[$legacyPendingKey]) && isset($needsCategoryMap[$currentPendingKey])) {
                $needsCategoryMap[$legacyPendingKey] = $needsCategoryMap[$currentPendingKey];
            }
            $statusCategoryMap = StatusCategory::query()
                ->get(['id', 'name'])
                ->mapWithKeys(fn (StatusCategory $status) => [$this->normalizeLookup($status->name) => $status->id])
                ->all();

            $file = $this->openFile($import->stored_path);
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $file->setCsvControl($this->detectDelimiter($import->stored_path), '"', '');

            $rowNumber = 0;
            $chunk = [];
            $errors = [];
            $successCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $totalRows = 0;

            foreach ($file as $row) {
                if (!is_array($row) || $row === [null]) {
                    continue;
                }

                $rowNumber++;

                if ($rowNumber === 1) {
                    continue;
                }

                $parsed = $this->parseCsvRow(
                    $row,
                    $rowNumber,
                    $import->user,
                    $validAccountIds,
                    $accountNameMap,
                    $needsCategoryMap,
                    $statusCategoryMap,
                    $defaultCategory->id,
                    $defaultStatus->id
                );

                if ($parsed === null) {
                    continue;
                }

                $totalRows++;

                if (is_string($parsed)) {
                    $errorCount++;
                    $errors[] = $parsed;
                    continue;
                }

                $chunk[] = $parsed;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    [$inserted, $updated] = $this->flushChunk(
                        $chunk,
                        $defaultCategory->id,
                        $defaultStatus->id,
                        $import->user_id
                    );

                    $successCount += $inserted;
                    $updatedCount += $updated;
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                [$inserted, $updated] = $this->flushChunk(
                    $chunk,
                    $defaultCategory->id,
                    $defaultStatus->id,
                    $import->user_id
                );

                $successCount += $inserted;
                $updatedCount += $updated;
            }

            $import->update([
                'status' => 'completed',
                'total_rows' => $totalRows,
                'success_count' => $successCount,
                'duplicate_count' => 0,
                'updated_count' => $updatedCount,
                'error_count' => $errorCount,
                'error_preview' => $this->summarizeErrors($errors),
                'finished_at' => now(),
            ]);

            $this->notifyPendingSurveyRequests($import->user);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'error_preview' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    /**
     * Beri tahu pengimpor bila ada lead yang sudah berada di tahap survey tapi
     * belum pernah diajukan ke manager surveyor.
     *
     * Import sengaja tidak membuat survey otomatis: tanggal dan jam survey
     * belum diketahui, dan manager tidak bisa menjadwalkan tanpa itu. Jadi
     * lead-nya ditandai, admin yang menindaklanjuti.
     */
    private function notifyPendingSurveyRequests(?User $user): void
    {
        if (! $user) {
            return;
        }

        $pending = Consultation::query()->forUser($user)->needsSurveyRequest()->count();

        // Hitungan ini ikut dipakai badge, jadi cache-nya harus disegarkan
        // walaupun tidak ada yang perlu diberitahukan.
        app(NotificationSummaryService::class)->forgetForUser((int) $user->id);

        if ($pending < 1) {
            return;
        }

        app(WebPushService::class)->sendToUsers([$user->id], [
            'title' => 'Lead menunggu pengajuan survey',
            'body' => "{$pending} lead sudah berstatus Request Survey tapi belum diajukan ke manager surveyor.",
            'url' => '/consultations?pending_survey=1',
            'tag' => 'pending-survey-'.$user->id,
        ]);
    }

    private function resolveDefaults(): array
    {
        // Dicari eksplisit, bukan lewat urutan sort_order: baris pertama urutan
        // itu kebetulan "Selesai Survey" - status terminal survei. Baris import
        // yang statusnya tak dikenali akan mengaku selesai disurvei padahal
        // tidak punya record survey sama sekali, sekaligus terkunci oleh
        // ConsultationController::surveyStatusConflict() sehingga tidak bisa
        // digeser lagi. Status netral jauh lebih aman sebagai jaring terakhir.
        $defaultStatus = StatusCategory::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', ['masih konsultasi'])
            ->first()
            ?? StatusCategory::query()->orderBy('sort_order')->first();

        // Dicari eksplisit, tidak lagi mengandalkan urutan scope: sejak
        // whitelist dibuang, baris pertama scope bisa saja kategori lain.
        $defaultCategory = NeedsCategory::query()
            ->whereIn('name', [NeedsCategory::PENDING_LABEL, NeedsCategory::PENDING_LEGACY_LABEL])
            ->orderByRaw('FIELD(name, ?) DESC', [NeedsCategory::PENDING_LABEL])
            ->first()
            ?? NeedsCategory::query()->orderBy('name')->first();

        if (!$defaultStatus || !$defaultCategory) {
            throw new RuntimeException('Master data Status atau Produk belum tersedia.');
        }

        return [$defaultStatus, $defaultCategory];
    }

    private function openFile(string $storedPath): SplFileObject
    {
        $absolutePath = Storage::path($storedPath);

        if (!is_file($absolutePath)) {
            throw new RuntimeException('File import tidak ditemukan di storage.');
        }

        return new SplFileObject($absolutePath);
    }

    private function flushChunk(
        array $chunk,
        int $defaultCategoryId,
        int $defaultStatusId,
        int $createdBy
    ): array {
        $inserted = 0;
        $updated = 0;

        foreach ($chunk as $row) {
            $categoryId = (int) ($row['needs_category_id'] ?? $defaultCategoryId);
            $statusId = (int) ($row['status_category_id'] ?? $defaultStatusId);

            // Kolom tunggal `needs_category_id` tetap diisi elemen pertama demi
            // kode lama; pivot-lah yang menyimpan daftar lengkapnya.
            $categoryIds = $row['needs_category_ids'] ?? [$categoryId];

            $consultation = DB::transaction(function () use ($createdBy, $categoryId, $categoryIds, $statusId, $row) {
                $existingLead = $this->findImportTarget($row, $categoryIds);

                $attributes = [
                    'client_name' => $row['client_name'],
                    'phone' => $row['phone'],
                    'province' => $row['province'] ?? null,
                    'city' => $row['city'] ?? null,
                    'district' => $row['district'] ?? null,
                    'address' => $row['address'] ?? null,
                    'account_id' => $row['account_id'],
                    'needs_category_id' => $categoryId,
                    'product_details' => $row['product_details'] ?? null,
                    'status_category_id' => $statusId,
                    'notes' => $row['notes'] ?? null,
                    'consultation_date' => $row['consultation_date'] ?? now()->toDateString(),
                ];

                if ($existingLead) {
                    if (method_exists($existingLead, 'trashed') && $existingLead->trashed()) {
                        $existingLead->restore();
                    }

                    $existingLead->update($attributes);

                    return [$existingLead, false];
                }

                $consultationId = $this->consultationIdForImport($row);
                $lead = Consultation::create(array_merge($attributes, [
                    'consultation_id' => $consultationId,
                    'created_by' => $createdBy,
                ]));

                $this->syncImportedConsultationSequence($consultationId, (int) $row['account_id']);

                return [$lead, true];
            }, 3);

            [$lead, $isNew] = $consultation;

            if (Consultation::hasNeedsCategoryPivot()) {
                $lead->needsCategories()->sync($categoryIds);
            }

            if ($isNew) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        return [$inserted, $updated];
    }

    /** @param  list<int>  $categoryIds */
    private function findImportTarget(array $row, array $categoryIds): ?Consultation
    {
        $consultationId = trim((string) ($row['consultation_id'] ?? ''));

        if ($consultationId !== '') {
            $lead = Consultation::query()
                ->withTrashed()
                ->where('consultation_id', $consultationId)
                ->where('account_id', $row['account_id'])
                ->first();

            if ($lead) {
                return $lead;
            }
        }

        return Consultation::findDuplicateLead([
            'account_id' => $row['account_id'],
            'client_name' => $row['client_name'],
            'phone' => $row['phone'],
            'province' => $row['province'] ?? null,
            'city' => $row['city'] ?? null,
            'district' => $row['district'] ?? null,
            'address' => $row['address'] ?? null,
            'product_details' => $row['product_details'] ?? null,
            'needs_category_ids' => $categoryIds,
        ]);
    }

    private function consultationIdForImport(array $row): string
    {
        $incomingId = $this->normalizeImportedConsultationId($row['consultation_id'] ?? null, (int) $row['account_id']);

        if ($incomingId && ! Consultation::query()->withTrashed()->where('consultation_id', $incomingId)->exists()) {
            return $incomingId;
        }

        return Consultation::generateConsultationId($row['account_id']);
    }

    private function normalizeImportedConsultationId(?string $value, int $accountId): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^(\d{2})\.(\d{4})\.(\d{4})$/', $value, $matches)) {
            return null;
        }

        if ($matches[1] !== str_pad((string) $accountId, 2, '0', STR_PAD_LEFT)) {
            return null;
        }

        return $value;
    }

    private function syncImportedConsultationSequence(string $consultationId, int $accountId): void
    {
        if (! preg_match('/^\d{2}\.(\d{4})\.(\d{4})$/', $consultationId, $matches)) {
            return;
        }

        $yearMonth = $matches[1];
        $number = (int) $matches[2];

        $now = now();
        $query = DB::table('consultation_sequences')
            ->where('account_id', $accountId)
            ->where('year_month', $yearMonth);

        $sequence = $query->lockForUpdate()->first();

        if ($sequence) {
            if ((int) $sequence->last_number < $number) {
                $query->update([
                    'last_number' => $number,
                    'updated_at' => $now,
                ]);
            }

            return;
        }

        DB::table('consultation_sequences')->insert([
            'account_id' => $accountId,
            'year_month' => $yearMonth,
            'last_number' => $number,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function parseCsvRow(
        array $row,
        int $rowNumber,
        ?User $user,
        array $validAccountIds,
        array $accountNameMap,
        array $needsCategoryMap,
        array $statusCategoryMap,
        int $defaultCategoryId,
        int $defaultStatusId
    ): array|string|null
    {
        if (!$user) {
            return "Baris {$rowNumber}: user import tidak ditemukan.";
        }

        $row = array_map(fn ($value) => trim((string) $value), $row);

        if (collect($row)->every(fn ($value) => $value === '')) {
            return null;
        }

        if ($this->isDetailedTemplateRow($row)) {
            return $this->parseDetailedTemplateRow(
                $row,
                $rowNumber,
                $user,
                $validAccountIds,
                $accountNameMap,
                $needsCategoryMap,
                $statusCategoryMap,
                $defaultCategoryId,
                $defaultStatusId
            );
        }

        if (!$this->isSimpleTemplateRow($row)) {
            return null;
        }

        $clientName = $this->stripCsvInjection($row[0] ?? '');
        $phone = $this->formatIndonesiaPhone($this->sanitizePhoneCell($row[1] ?? ''));

        if ($clientName === '' || $phone === '') {
            return "Baris {$rowNumber}: nama klien atau telepon kosong.";
        }

        if ($user->isAdmin()) {
            $accountId = $user->account_id;
        } else {
            $rawAccountId = trim((string) ($row[2] ?? ''));

            if (is_numeric($rawAccountId) && in_array((int) $rawAccountId, $validAccountIds, true)) {
                $accountId = (int) $rawAccountId;
            } elseif ($rawAccountId !== '' && isset($accountNameMap[$this->normalizeLookup($rawAccountId)])) {
                $accountId = (int) $accountNameMap[$this->normalizeLookup($rawAccountId)];
            } elseif ($rawAccountId !== '') {
                return "Baris {$rowNumber}: Akun '{$rawAccountId}' tidak ditemukan di database.";
            } else {
                $accountId = $validAccountIds[0] ?? null;
            }
        }

        if (!$accountId) {
            return "Baris {$rowNumber}: Tidak ada akun tersedia.";
        }

        return [
            'client_name' => $clientName,
            'phone' => $phone,
            // Template sederhana tidak punya kolom wilayah; tetap diberi label
            // placeholder agar konsisten dengan lead yang dibuat lewat form.
            'province' => PendingConfirmation::REGION_LABEL,
            'city' => PendingConfirmation::REGION_LABEL,
            'district' => PendingConfirmation::REGION_LABEL,
            'address' => null,
            'product_details' => null,
            'account_id' => (int) $accountId,
            'consultation_id' => trim((string) ($row[3] ?? '')),
            'needs_category_id' => $defaultCategoryId,
            'status_category_id' => $defaultStatusId,
            'consultation_date' => now()->toDateString(),
        ];
    }

    private function parseDetailedTemplateRow(
        array $row,
        int $rowNumber,
        User $user,
        array $validAccountIds,
        array $accountNameMap,
        array $needsCategoryMap,
        array $statusCategoryMap,
        int $defaultCategoryId,
        int $defaultStatusId
    ): array|string {
        $accountName = preg_replace('/^[=+\-\@\t\r\n]/', '', $row[3] ?? '');
        $clientName = preg_replace('/^[=+\-\@\t\r\n]/', '', $row[4] ?? '');
        $phone = $this->formatIndonesiaPhone($this->sanitizePhoneCell($row[5] ?? ''));

        if ($clientName === '' || $phone === '') {
            return "Baris {$rowNumber}: nama konsumen atau WA konsumen kosong.";
        }

        if ($user->isAdmin()) {
            $accountId = $user->account_id;
        } else {
            $accountId = $accountNameMap[$this->normalizeLookup($accountName)] ?? null;

            if (!$accountId && is_numeric($accountName) && in_array((int) $accountName, $validAccountIds, true)) {
                $accountId = (int) $accountName;
            }

            if (!$accountId) {
                return "Baris {$rowNumber}: akun '{$accountName}' tidak ditemukan di database.";
            }
        }

        // Template terbaru menyisipkan kolom Kecamatan di posisi J, menggeser
        // kolom setelahnya satu langkah. File lama (14 kolom) tetap diterima.
        $hasDistrictColumn = $this->hasDistrictColumn($row, $needsCategoryMap);
        $shift = $hasDistrictColumn ? 1 : 0;

        $needIndex = 9 + $shift;
        $detailIndex = 10 + $shift;
        $notesIndex = 11 + $shift;
        $statusIndex = 12 + $shift;

        $needsCategoryIds = $this->resolveNeedsCategoryIds(
            $row[$needIndex] ?? '',
            $needsCategoryMap,
            $defaultCategoryId
        );
        $statusCategoryId = $statusCategoryMap[$this->normalizeLookup($row[$statusIndex] ?? '')] ?? $defaultStatusId;

        // Ejaan wilayah disamakan ke format master; kecamatan yang diketik
        // manual dirapikan ke ejaan dataset bila ditemukan padanannya.
        $province = WilayahNormalizer::canonicalProvince($row[7] ?? null) ?? ($row[7] !== '' ? $row[7] : null);
        $city = WilayahNormalizer::canonicalCity($row[8] ?? null) ?? ($row[8] !== '' ? $row[8] : null);

        $district = null;
        if ($hasDistrictColumn && ($row[9] ?? '') !== '') {
            $resolved = WilayahNormalizer::canonicalDistrict($row[9], $city);
            $district = $resolved['value'];

            if (! $resolved['matched']) {
                Log::warning('Import lead: kecamatan tidak dikenali, dipakai apa adanya.', [
                    'row' => $rowNumber,
                    'kecamatan' => $district,
                    'kota' => $city,
                ]);
            }
        }

        return [
            'consultation_id' => preg_replace('/^[=+\-\@\t\r\n]/', '', $row[1] ?? ''),
            'client_name' => $this->stripHtmlDelimiters($clientName),
            'phone' => $phone,
            'province' => PendingConfirmation::normalizeRegion($this->stripHtmlDelimiters($province)),
            'city' => PendingConfirmation::normalizeRegion($this->stripHtmlDelimiters($city)),
            'district' => PendingConfirmation::normalizeRegion($this->stripHtmlDelimiters($district)),
            // Kolom G template adalah Domisili (Dalam/Luar Kota) yang terisi
            // rumus otomatis, bukan alamat. Sebelumnya nilai itu tertulis ke
            // kolom address. Template tidak punya kolom alamat, jadi dikosongkan.
            'address' => null,
            'product_details' => $this->stripHtmlDelimiters(
                ($row[$detailIndex] ?? '') !== ''
                    ? $row[$detailIndex]
                    : (($row[$needIndex] ?? '') !== '' ? $row[$needIndex] : null)
            ),
            'notes' => $this->stripHtmlDelimiters(
                ($row[$notesIndex] ?? '') !== '' ? $row[$notesIndex] : null
            ),
            'account_id' => (int) $accountId,
            'needs_category_id' => (int) $needsCategoryIds[0],
            'needs_category_ids' => $needsCategoryIds,
            'status_category_id' => (int) $statusCategoryId,
            'consultation_date' => $this->parseDate($row[2] ?? null) ?? now()->toDateString(),
        ];
    }

    /**
     * Buang '<' dan '>' dari teks bebas hasil impor.
     *
     * Baris CSV tidak melewati ConsultationRequest, jadi aturan karakter di sana
     * — `regex:/^[\pL0-9\s\-.,]+$/u` untuk wilayah, `/^[^<>]+$/` untuk alamat —
     * tidak berlaku untuk jalur ini. Nilai wilayah yang tidak dikenali disimpan
     * apa adanya (lihat canonicalProvince() ?? $row[7] di atas), sehingga jalur
     * impor bisa menaruh HTML sembarang ke kolom yang jalur API sudah tolak.
     *
     * Karakter dibuang, bukan barisnya ditolak: tidak ada nama tempat, produk,
     * atau catatan sah yang memuat '<' atau '>', jadi ini tidak menggagalkan
     * impor yang selama ini berhasil. Sejalan dengan pembersihan awalan formula
     * pada `consultation_id` di fungsi yang sama.
     */
    private function stripHtmlDelimiters(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(['<', '>'], '', $value);
    }

    /**
     * Terjemahkan sel Jenis Kebutuhan jadi daftar id kategori.
     *
     * Sel boleh memuat lebih dari satu kategori dipisah koma, mis.
     * "Kitchenset, Backdrop TV" - itu format yang dipakai export aplikasi
     * sendiri. Sebelumnya sel semacam itu dicocokkan utuh, tidak pernah
     * ketemu, lalu jatuh ke kategori default sehingga kebutuhannya hilang.
     *
     * @param  array<string, int>  $needsCategoryMap
     * @return list<int> minimal berisi satu id
     */
    private function resolveNeedsCategoryIds(
        string $value,
        array $needsCategoryMap,
        int $defaultCategoryId
    ): array {
        $ids = [];

        foreach (explode(',', $value) as $part) {
            $id = $needsCategoryMap[$this->normalizeLookup($part)] ?? null;

            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids)) ?: [$defaultCategoryId];
    }

    /**
     * Tentukan apakah baris berasal dari template 15 kolom (ada Kecamatan).
     *
     * Lebar baris jadi petunjuk utama; bila ambigu, posisi kolom Jenis Kebutuhan
     * yang menentukan - pada tata letak baru ia ada di indeks 10, pada tata
     * letak lama di indeks 9.
     */
    private function hasDistrictColumn(array $row, array $needsCategoryMap): bool
    {
        $needAt = fn (int $index) => isset($needsCategoryMap[$this->normalizeLookup($row[$index] ?? '')]);

        if ($needAt(10) && ! $needAt(9)) {
            return true;
        }

        if ($needAt(9) && ! $needAt(10)) {
            return false;
        }

        return count($row) >= 15;
    }

    private function isDetailedTemplateRow(array $row): bool
    {
        $account = $this->normalizeLookup($row[3] ?? '');
        $client = $this->normalizeLookup($row[4] ?? '');
        $phone = $this->normalizeLookup($row[5] ?? '');

        return $account !== ''
            && $client !== ''
            && $phone !== ''
            && !in_array($client, ['nama konsumen', 'data konsumen'], true)
            && !in_array($phone, ['wa konsumen', 'telepon', 'no telepon'], true);
    }

    private function isSimpleTemplateRow(array $row): bool
    {
        $client = $this->normalizeLookup($row[0] ?? '');
        $phone = $this->normalizeLookup($row[1] ?? '');

        return $client !== ''
            && $phone !== ''
            && !in_array($client, ['nama klien', 'nama konsumen', 'no', '#'], true)
            && preg_match('/\d/', $phone) === 1;
    }

    private function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Sel tanggal yang diekspor Excel kerap membawa jam ("2026-07-16
        // 00:00:00") atau memakai tahun dua digit ("16/07/26"). Keduanya
        // sebelumnya ditolak dan baris ikut memakai tanggal hari ini, sehingga
        // seluruh lead hasil import kehilangan tanggal konsultasi aslinya.
        // Format bertahun 4 digit didahulukan supaya "01/02/2026" tidak
        // keburu tertangkap pola dua digit.
        foreach ([
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'm/d/Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y/m/d',
            'd/m/y',
            'd-m-y',
        ] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function detectDelimiter(string $storedPath): string
    {
        $absolutePath = Storage::path($storedPath);
        $sample = is_file($absolutePath) ? (file($absolutePath, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $lines = collect($sample)->take(10)->implode("\n");
        $delimiters = [',' => substr_count($lines, ','), ';' => substr_count($lines, ';'), "\t" => substr_count($lines, "\t")];

        arsort($delimiters);

        return array_key_first($delimiters) ?: ',';
    }

    private function normalizeLookup(?string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $value)));
    }

    /**
     * Normalisasi nomor dari file import ke E.164. Nomor tanpa "+" dianggap
     * nomor Indonesia; nomor asing yang sudah membawa kode negara dipertahankan
     * apa adanya. Nilai yang tidak bisa diurai dikembalikan setelah dirapikan
     * supaya baris tetap bisa diimpor dan ditinjau manual.
     */
    private function formatIndonesiaPhone(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $tidy = trim((string) $value);

        return PhoneNumber::toE164($tidy) ?? ($tidy !== '' ? $tidy : null);
    }

    private function summarizeErrors(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        return collect($errors)->take(10)->implode("\n");
    }

    /**
     * F-016: Strip CSV injection characters from the start of a cell value.
     * Characters =, +, -, @, tab, CR, LF at the start of a cell are interpreted
     * as formula prefixes by spreadsheet apps like Excel, enabling CSV injection.
     */
    private function stripCsvInjection(mixed $value): string
    {
        return preg_replace('/^[=+\-\@\t\r\n]/', '', trim((string) ($value ?? '')));
    }

    /**
     * Sanitasi kolom telepon.
     *
     * stripCsvInjection() membuang "+" di awal, sehingga "+60123456789" berubah
     * jadi "60123456789" lalu terbaca sebagai nomor Indonesia (+6260...).
     * Di sini "+" dipertahankan selama sisanya benar-benar berbentuk nomor -
     * hanya angka dan pemisah - jadi tidak ada rumus yang bisa lolos.
     */
    private function sanitizePhoneCell(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));

        if (preg_match('/^\+[0-9()\-.\s]+$/', $raw) === 1) {
            return $raw;
        }

        return $this->stripCsvInjection($raw);
    }
}
