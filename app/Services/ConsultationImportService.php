<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'error_preview' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function resolveDefaults(): array
    {
        $defaultStatus = StatusCategory::query()->orderBy('sort_order')->first();
        $defaultCategory = NeedsCategory::query()->forConsultationOptions()->first() ?? NeedsCategory::query()->orderBy('name')->first();

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

            $consultation = DB::transaction(function () use ($createdBy, $categoryId, $statusId, $row) {
                $existingLead = $this->findImportTarget($row, $categoryId);

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
                $lead->needsCategories()->sync([$categoryId]);
            }

            if ($isNew) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        return [$inserted, $updated];
    }

    private function findImportTarget(array $row, int $categoryId): ?Consultation
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
            'needs_category_ids' => [$categoryId],
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
        $phone = $this->formatIndonesiaPhone($this->stripCsvInjection($row[1] ?? ''));

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
            'province' => null,
            'city' => null,
            'district' => null,
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
        $phone = $this->formatIndonesiaPhone(preg_replace('/^[=+\-\@\t\r\n]/', '', $row[5] ?? ''));

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

        $needsCategoryId = $needsCategoryMap[$this->normalizeLookup($row[9] ?? '')] ?? $defaultCategoryId;
        $statusCategoryId = $statusCategoryMap[$this->normalizeLookup($row[12] ?? '')] ?? $defaultStatusId;

        return [
            'consultation_id' => preg_replace('/^[=+\-\@\t\r\n]/', '', $row[1] ?? ''),
            'client_name' => $clientName,
            'phone' => $phone,
            'province' => $row[7] !== '' ? $row[7] : null,
            'city' => $row[8] !== '' ? $row[8] : null,
            'district' => null,
            'address' => $row[6] !== '' ? $row[6] : null,
            'product_details' => $row[10] !== '' ? $row[10] : ($row[9] !== '' ? $row[9] : null),
            'notes' => $row[11] !== '' ? $row[11] : null,
            'account_id' => (int) $accountId,
            'needs_category_id' => (int) $needsCategoryId,
            'status_category_id' => (int) $statusCategoryId,
            'consultation_date' => $this->parseDate($row[2] ?? null) ?? now()->toDateString(),
        ];
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

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y'] as $format) {
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

    private function formatIndonesiaPhone(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '620')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '62')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        $segments = [substr($digits, 0, 3)];
        $remaining = substr($digits, 3);

        while (strlen($remaining) > 4) {
            $segments[] = substr($remaining, 0, 4);
            $remaining = substr($remaining, 4);
        }

        if ($remaining !== '') {
            $segments[] = $remaining;
        }

        return '+62 ' . implode('-', array_filter($segments));
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
}
