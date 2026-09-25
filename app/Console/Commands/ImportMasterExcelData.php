<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\Survey;
use App\Models\User;
use App\Services\ConsultationImportService;
use App\Support\AccountGroup;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Import satu-kali data konsultasi + survey dari Excel master Team A-F
 * (dikonversi ke CSV format "Template Leads" + JSON baris survey di luar
 * command ini). Tahap konsultasi reuse ConsultationImportService (matching
 * akun/kategori/status, generate consultation_id, normalisasi telepon/
 * wilayah — sama persis dengan alur import CSV yang sudah ada di app).
 * Tahap survey dibuat manual karena ConsultationImportService sengaja TIDAK
 * membuat Survey (tanggal/jam belum tentu diketahui saat import biasa) —
 * di sini tanggal survey historis sudah ada di sumber data.
 */
class ImportMasterExcelData extends Command
{
    protected $signature = 'consultations:import-master-excel
        {csv : Path CSV hasil konversi Excel (format Template Leads 15 kolom)}
        {surveys : Path JSON baris survey (row, akun, group, surveyor, tgl_survey, status_lanjutan, catatan)}
        {--apply : Tulis perubahan ke database (tanpa opsi ini cuma preview)}
        {--user=1 : ID user super_admin pemilik import}';

    protected $description = 'Import data konsultasi + survey dari CSV/JSON hasil konversi Excel master Team A-F, termasuk deteksi surveyor GACONG (lintas tim)';

    /** Alias nama surveyor Excel -> nama user aplikasi (ejaan beda, orang sama, dikonfirmasi manual 2026-09-25). */
    private const SURVEYOR_ALIASES = [
        'CHANDRA' => 'CANDRA',
    ];

    public function handle(ConsultationImportService $importService): int
    {
        $csvPath = $this->argument('csv');
        $surveysPath = $this->argument('surveys');
        $apply = (bool) $this->option('apply');

        if (! is_file($csvPath)) {
            $this->error("File CSV tidak ditemukan: {$csvPath}");

            return self::FAILURE;
        }

        if (! is_file($surveysPath)) {
            $this->error("File JSON survey tidak ditemukan: {$surveysPath}");

            return self::FAILURE;
        }

        $user = User::find((int) $this->option('user'));

        if (! $user) {
            $this->error('User pemilik import tidak ditemukan (--user).');

            return self::FAILURE;
        }

        $this->info($apply
            ? '=== MODE APPLY — menulis ke database ==='
            : '=== MODE DRY-RUN — preview saja, tambahkan --apply untuk eksekusi ===');
        $this->newLine();

        $this->info('--- Tahap 1: Konsultasi ---');

        if (! $apply) {
            $this->previewConsultations($csvPath);
        } else {
            $import = ConsultationImport::create([
                'user_id' => $user->id,
                'original_name' => basename($csvPath),
                'stored_path' => $this->storeCsv($csvPath),
                'status' => 'queued',
            ]);

            $importService->process($import);
            $import->refresh();

            $this->table(['Metrik', 'Nilai'], [
                ['Total baris terbaca', $import->total_rows],
                ['Konsultasi baru dibuat', $import->success_count],
                ['Ter-update (duplikat cocok)', $import->updated_count],
                ['Gagal', $import->error_count],
            ]);

            if ($import->error_preview) {
                $this->warn('Baris gagal:');
                $this->line($import->error_preview);
            }
        }

        $this->newLine();
        $this->info('--- Tahap 2: Survey ---');
        $this->importSurveys($surveysPath, $user, $apply);

        return self::SUCCESS;
    }

    private function storeCsv(string $path): string
    {
        $stored = 'imports/consultations/'.uniqid('master_excel_', true).'.csv';
        Storage::put($stored, file_get_contents($path));

        return $stored;
    }

    /**
     * Preview ringan tanpa menulis DB: cek tiap baris CSV terhadap akun/
     * kategori/status live, laporkan yang bakal gagal keras (akun tidak
     * ketemu) atau jatuh ke default diam-diam (kategori/status tidak match).
     */
    private function previewConsultations(string $csvPath): void
    {
        $accounts = Account::query()->pluck('id', DB::raw('LOWER(TRIM(name))'));
        $categories = NeedsCategory::query()->pluck('id', DB::raw('LOWER(TRIM(name))'));
        $statuses = StatusCategory::query()->pluck('id', DB::raw('LOWER(TRIM(name))'));

        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        $total = 0;
        $accountErrors = [];
        $categoryFallback = [];
        $statusFallback = [];

        foreach ($rows as $i => $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $total++;
            $rowNum = $i + 2;

            $accountName = strtolower(trim($row[3] ?? ''));
            if ($accountName !== '' && ! isset($accounts[$accountName])) {
                $accountErrors[] = "Baris {$rowNum}: akun '{$row[3]}' tidak ditemukan.";
            }

            $categoryName = strtolower(trim(explode(',', $row[10] ?? '')[0] ?? ''));
            if ($categoryName !== '' && ! isset($categories[$categoryName])) {
                $categoryFallback[] = "Baris {$rowNum}: kategori '{$row[10]}' tidak exact-match, jatuh ke default.";
            }

            $statusName = strtolower(trim($row[13] ?? ''));
            if ($statusName !== '' && ! isset($statuses[$statusName])) {
                $statusFallback[] = "Baris {$rowNum}: status '{$row[13]}' tidak exact-match, jatuh ke default.";
            }
        }

        $this->table(['Metrik', 'Nilai'], [
            ['Total baris berisi data', $total],
            ['Akun tidak ketemu (GAGAL keras)', count($accountErrors)],
            ['Kategori tidak exact-match (fallback default)', count($categoryFallback)],
            ['Status tidak exact-match (fallback default)', count($statusFallback)],
        ]);

        foreach (array_merge($accountErrors, $categoryFallback, $statusFallback) as $line) {
            $this->line('  - '.$line);
        }
    }

    private function importSurveys(string $surveysPath, User $user, bool $apply): void
    {
        $surveyRows = json_decode(file_get_contents($surveysPath), true) ?: [];
        $this->line(count($surveyRows).' baris survey ditemukan.');

        // withTrashed(): surveyor historis bisa saja sudah di-retire (soft-deleted)
        // dari roster aktif setelah reorganisasi Team A-F — data survey lama tetap
        // sah menyebut mereka. Pola sama seperti Survey::surveyor() withTrashed().
        $surveyorMap = User::withTrashed()
            ->where('role', 'surveyor')
            ->get(['id', 'name', 'survey_team'])
            ->keyBy(fn (User $u) => Str::upper(trim($u->name)));

        $accountInfoByName = [];
        foreach (Account::query()->get(['id', 'name', 'account_group']) as $account) {
            $accountInfoByName[Str::upper(trim($account->name))] = $account;
        }

        $created = 0;
        $skipped = [];

        foreach ($surveyRows as $row) {
            $akunKey = Str::upper(trim($row['akun']));
            /** @var Account|null $account */
            $account = $accountInfoByName[$akunKey] ?? null;

            if (! $account) {
                $skipped[] = "Row {$row['row']}: akun '{$row['akun']}' tidak ditemukan.";

                continue;
            }

            $surveyorNameRaw = Str::upper(trim($row['surveyor']));
            $surveyorName = self::SURVEYOR_ALIASES[$surveyorNameRaw] ?? $surveyorNameRaw;
            /** @var User|null $surveyor */
            $surveyor = $surveyorMap->get($surveyorName);

            if (! $surveyor) {
                $skipped[] = "Row {$row['row']}: surveyor '{$row['surveyor']}' tidak ditemukan di users.";

                continue;
            }

            // Cocokkan lewat nomor HP (phone_normalized), bukan account+tanggal --
            // satu akun bisa punya beberapa lead beda orang di tanggal yang sama
            // (row 30 & 33 sama-sama HOME PUTRA INTERIOR, 23 Sept, surveyor beda),
            // match by date saja pernah nabrak 2 baris survey ke 1 consultation
            // yang sama -> unique constraint surveys_active_key_unique.
            $phoneKey = PhoneNumber::key($row['phone'] ?? null);
            $consultation = Consultation::query()
                ->where('account_id', $account->id)
                ->where('phone_normalized', $phoneKey)
                ->orderByDesc('id')
                ->first();

            if (! $consultation) {
                $skipped[] = "Row {$row['row']}: konsultasi terkait tidak ditemukan (akun {$row['akun']}, telepon {$row['phone']}).";

                continue;
            }

            // Idempotent: kalau consultation ini sudah punya survey aktif
            // (mis. command diulang setelah percobaan sebelumnya sebagian
            // berhasil), jangan coba buat lagi -- lapor & skip.
            if ($consultation->activeSurvey()->exists()) {
                $skipped[] = "Row {$row['row']}: consultation {$consultation->consultation_id} sudah punya survey aktif, di-skip (sudah pernah diimport).";

                continue;
            }

            $isGacong = $surveyor->survey_team !== $account->account_group;
            $gacongNote = null;

            if ($isGacong) {
                $gacongNote = $account->account_group === AccountGroup::TEAM_F
                    ? "[GACONG] Surveyor pinjaman dari Team {$surveyor->survey_team} - Team F belum punya surveyor sendiri."
                    : "[GACONG] Surveyor pinjaman dari Team {$surveyor->survey_team} untuk Team {$account->account_group}.";
            }

            $this->line(sprintf(
                '  %s Row %s: %s (Team %s) -> %s (Team %s)',
                $isGacong ? '[GACONG]' : '[normal]',
                $row['row'],
                $row['akun'],
                $account->account_group,
                $surveyor->name,
                $surveyor->survey_team ?? '-'
            ));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($consultation, $account, $surveyor, $row, $gacongNote, $user) {
                $scheduledAt = $row['tgl_survey'] ?: now()->toDateString();

                $survey = new Survey([
                    'consultation_id' => $consultation->id,
                    'account_id' => $account->id,
                    'requested_by' => $user->id,
                    'requested_at' => $consultation->consultation_date,
                    'requested_date' => $row['tgl_survey'] ?: null,
                ]);
                $survey->save();
                $survey->transitionTo(Survey::STATE_REQUESTED);

                $survey->surveyor_id = $surveyor->id;
                $survey->assigned_by = $user->id;
                $survey->assigned_at = $scheduledAt;
                $survey->scheduled_at = $scheduledAt;
                $survey->location_notes = $gacongNote;
                $survey->save();
                $survey->transitionTo(Survey::STATE_SCHEDULED);

                // status_lanjutan sumber Excel semuanya "HOLD" (belum DEAL/CANCEL) —
                // disisakan di state scheduled, belum ditransisikan lebih jauh.
            });

            $created++;
        }

        if ($apply) {
            $this->info("Survey dibuat: {$created}");
        }

        if ($skipped !== []) {
            $this->warn('Baris survey yang di-skip:');
            foreach ($skipped as $line) {
                $this->line('  - '.$line);
            }
        }
    }
}
