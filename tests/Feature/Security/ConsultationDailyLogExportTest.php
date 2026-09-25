<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ReportAttendance;
use App\Models\User;
use App\Services\Reports\AdminReportAttendanceExcelExporter;
use App\Services\Reports\ConsultationDailyLogExcelExporter;
use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Format kedua (log harian) dari rekap konsul harus:
 *  - hanya bisa diakses super_admin (403 buat role lain, sama seperti export() matrix);
 *  - menghasilkan status/warna yang IDENTIK dengan matrix untuk kombinasi
 *    akun+tanggal yang sama — inilah yang dibuktikan refactor
 *    ConsultationRecapStatusResolver tidak mengubah perilaku.
 */
class ConsultationDailyLogExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_export_log_and_other_roles_are_forbidden(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $account = $this->createAccount('Log Export Akun', AccountGroup::TEAM_A);

        Sanctum::actingAs($this->createUser(UserRole::SuperAdmin));
        $response = $this->get('/api/v1/report-attendances/export-log?date=2026-09-23');
        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));

        Sanctum::actingAs($this->createUser(UserRole::Admin, $account));
        $this->get('/api/v1/report-attendances/export-log?date=2026-09-23')->assertForbidden();
    }

    public function test_log_and_matrix_export_agree_on_status_for_the_same_account_and_date(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $accountWithConsult = $this->createAccount('Ada Wa Akun', AccountGroup::TEAM_A);
        $accountSilent = $this->createAccount('Tidak Laporan Akun', AccountGroup::TEAM_A);

        // Konsultasi dibuat hari yang sama dengan consultation_date -> tepat
        // waktu -> hijau/ADA WA. $accountSilent sengaja tidak punya apa-apa
        // sama sekali -> merah/TIDAK LAPORAN.
        Consultation::query()->create([
            'consultation_id' => 'CNS-TEST-0001',
            'client_name' => 'Klien Uji',
            'account_id' => $accountWithConsult->id,
            'consultation_date' => Carbon::parse('2026-09-23'),
        ]);

        $date = Carbon::parse('2026-09-23');
        $accountIds = [$accountWithConsult->id, $accountSilent->id];

        $matrixXml = app(AdminReportAttendanceExcelExporter::class)
            ->buildWorkbook($date, null, $date->copy(), $accountIds);
        $logXml = app(ConsultationDailyLogExcelExporter::class)
            ->buildWorkbook($date, null, $date->copy(), $accountIds);

        $this->assertRowHasStyle($matrixXml, 'ADA WA AKUN', 'statusAdaWa');
        $this->assertRowHasStyle($matrixXml, 'TIDAK LAPORAN AKUN', 'statusTidakLaporan');

        $this->assertRowHasStyle($logXml, 'ADA WA AKUN', 'statusAdaWa');
        $this->assertRowContainsText($logXml, 'ADA WA AKUN', '>ADA WA<');
        $this->assertRowHasStyle($logXml, 'TIDAK LAPORAN AKUN', 'statusTidakLaporan');
        $this->assertRowContainsText($logXml, 'TIDAK LAPORAN AKUN', '>TIDAK LAPORAN<');
    }

    public function test_log_export_filters_by_account_group(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));

        $teamAAccount = $this->createAccount('Akun Tim A', AccountGroup::TEAM_A);
        $teamBAccount = $this->createAccount('Akun Tim B', AccountGroup::TEAM_B);

        $xml = app(ConsultationDailyLogExcelExporter::class)
            ->buildWorkbook(Carbon::parse('2026-09-23'), AccountGroup::TEAM_A);

        $this->assertStringContainsString('AKUN TIM A', $xml);
        $this->assertStringNotContainsString('AKUN TIM B', $xml);
    }

    /**
     * B5: perluasan cakupan ke SEMUA 5 prioritas warna resolver (sebelumnya
     * cuma hijau/ADA WA dan merah/TIDAK LAPORAN yang ditest), dibuktikan
     * identik di kedua format export untuk kombinasi akun+tanggal yang sama.
     */
    public function test_both_exports_agree_on_all_five_status_priorities(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $date = Carbon::parse('2026-09-23');

        $susulanAccount = $this->createAccount('Susulan Akun', AccountGroup::TEAM_A);
        $susulanConsultation = Consultation::query()->create([
            'consultation_id' => 'CNS-TEST-0002',
            'client_name' => 'Klien Susulan',
            'account_id' => $susulanAccount->id,
            'consultation_date' => $date,
        ]);
        // created_at bukan fillable (audit trail) - resolver membandingkan
        // DATE(created_at) vs consultation_date buat menentukan susulan,
        // jadi baris ini perlu created_at SETELAH tanggal konsul buat
        // benar-benar mensimulasikan laporan susulan.
        $susulanConsultation->forceFill(['created_at' => $date->copy()->addDay()])->save();

        $nolWaAccount = $this->createAccount('Nol Wa Akun', AccountGroup::TEAM_A);
        $this->reportAttendance($nolWaAccount, $date, 'nol_wa');

        $liburAccount = $this->createAccount('Libur Akun', AccountGroup::TEAM_A);
        $this->reportAttendance($liburAccount, $date, 'libur_susulan');

        $accountIds = [$susulanAccount->id, $nolWaAccount->id, $liburAccount->id];

        $matrixXml = app(AdminReportAttendanceExcelExporter::class)->buildWorkbook($date, null, $date->copy(), $accountIds);
        $logXml = app(ConsultationDailyLogExcelExporter::class)->buildWorkbook($date, null, $date->copy(), $accountIds);

        foreach ([
            ['SUSULAN AKUN', 'statusLibur', '>SUSULAN'],
            ['NOL WA AKUN', 'statusNolWa', '>0 DATA<'],
            ['LIBUR AKUN', 'statusLibur', '>SUSULAN'],
        ] as [$accountName, $style, $needle]) {
            $this->assertRowHasStyle($matrixXml, $accountName, $style);
            $this->assertRowHasStyle($logXml, $accountName, $style);
            $this->assertRowContainsText($logXml, $accountName, $needle);
        }
    }

    /**
     * Satu akun, dua admin: satu lapor kerja 0 data (nol_wa), satu lapor
     * libur. Aturan resolver #3 didahulukan atas #4 supaya baris ini terbaca
     * kuning ("admin bekerja hari itu"), bukan biru seolah seluruh akun
     * libur - kalau ini gagal, versi refactor resolver diam-diam mengubah
     * urutan prioritas.
     */
    public function test_multiple_admins_working_takes_priority_over_day_off(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $date = Carbon::parse('2026-09-23');

        $account = $this->createAccount('Dua Admin Akun', AccountGroup::TEAM_A);
        $this->reportAttendance($account, $date, 'nol_wa');
        $this->reportAttendance($account, $date, 'libur_susulan');

        $xml = app(ConsultationDailyLogExcelExporter::class)->buildWorkbook($date, null, $date->copy(), [$account->id]);

        $this->assertRowHasStyle($xml, 'DUA ADMIN AKUN', 'statusNolWa');
        $this->assertRowContainsText($xml, 'DUA ADMIN AKUN', '>0 DATA<');
    }

    /** Akun tanpa admin sama sekali tetap muncul di daftar (merah) - bukan diam-diam hilang. */
    public function test_account_without_any_admin_still_appears_as_no_report(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $date = Carbon::parse('2026-09-23');
        $account = $this->createAccount('Tanpa Admin Akun', AccountGroup::TEAM_A);

        $xml = app(ConsultationDailyLogExcelExporter::class)->buildWorkbook($date, null, $date->copy(), [$account->id]);

        $this->assertRowHasStyle($xml, 'TANPA ADMIN AKUN', 'statusTidakLaporan');
    }

    /** Lead yang di-soft-delete tidak boleh ikut dihitung sebagai laporan tepat waktu. */
    public function test_soft_deleted_consultation_is_excluded_from_status(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        $date = Carbon::parse('2026-09-23');
        $account = $this->createAccount('Soft Delete Akun', AccountGroup::TEAM_A);

        $consultation = Consultation::query()->create([
            'consultation_id' => 'CNS-TEST-0003',
            'client_name' => 'Klien Terhapus',
            'account_id' => $account->id,
            'consultation_date' => $date,
        ]);
        $consultation->delete();

        $xml = app(ConsultationDailyLogExcelExporter::class)->buildWorkbook($date, null, $date->copy(), [$account->id]);

        $this->assertRowHasStyle($xml, 'SOFT DELETE AKUN', 'statusTidakLaporan');
    }

    /**
     * B5: rentang >92 hari dipotong exporter (perilaku lama, dipertahankan),
     * TAPI nama file & header sekarang wajib mencerminkan itu - sebelum fix
     * ini nama file mengklaim rentang penuh yang diminta walau isinya cuma
     * 92 hari pertama.
     */
    public function test_export_filename_reflects_truncated_range_not_the_requested_one(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        Sanctum::actingAs($this->createUser(UserRole::SuperAdmin));

        $start = '2026-01-01';
        $end = '2026-12-31'; // 365 hari, jauh melebihi batas 92 hari
        $expectedTruncatedEnd = Carbon::parse($start)->addDays(91)->format('Ymd'); // 92 hari inklusif

        $response = $this->get("/api/v1/report-attendances/export-log?start_date={$start}&end_date={$end}");
        $response->assertOk();
        $response->assertHeader('X-Recap-Range-Truncated', '1');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('20260101-'.$expectedTruncatedEnd, $disposition);
        $this->assertStringNotContainsString('20260101-20261231', $disposition, 'Nama file tidak boleh mengklaim rentang penuh yang diminta.');

        $matrixResponse = $this->get("/api/v1/report-attendances/export?start_date={$start}&end_date={$end}");
        $matrixResponse->assertOk();
        $matrixResponse->assertHeader('X-Recap-Range-Truncated', '1');
        $this->assertStringContainsString(
            '20260101-'.$expectedTruncatedEnd,
            (string) $matrixResponse->headers->get('Content-Disposition')
        );
    }

    /** Rentang di dalam batas 92 hari TIDAK ditandai terpotong. */
    public function test_export_within_range_limit_is_not_flagged_truncated(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        Sanctum::actingAs($this->createUser(UserRole::SuperAdmin));

        $response = $this->get('/api/v1/report-attendances/export-log?start_date=2026-09-01&end_date=2026-09-23');
        $response->assertOk();
        $response->assertHeader('X-Recap-Range-Truncated', '0');
        $this->assertStringContainsString('20260901-20260923', (string) $response->headers->get('Content-Disposition'));
    }

    /** Rentang tanggal terbalik ditolak validasi, bukan diam-diam ditukar lalu diproses. */
    public function test_reversed_date_range_is_rejected(): void
    {
        Sanctum::actingAs($this->createUser(UserRole::SuperAdmin));

        $this->get('/api/v1/report-attendances/export-log?start_date=2026-09-23&end_date=2026-09-01')
            ->assertStatus(422);
    }

    /** ID akun yang tidak ada di database diabaikan dengan aman (hasil kosong), bukan 500. */
    public function test_export_with_nonexistent_account_id_does_not_error(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 10:00:00'));
        Sanctum::actingAs($this->createUser(UserRole::SuperAdmin));

        $this->get('/api/v1/report-attendances/export-log?date=2026-09-23&account_ids=999999')
            ->assertOk();
    }

    private function reportAttendance(Account $account, Carbon $date, string $category): void
    {
        static $sequence = 0;
        $sequence++;
        $admin = $this->createUser(UserRole::Admin, $account);
        ReportAttendance::query()->create([
            'user_id' => $admin->id,
            'account_id' => $account->id,
            'report_date' => $date->toDateString(),
            'report_category' => $category,
        ]);
    }

    /** Baris <Row>...</Row> yang memuat nama akun tertentu harus punya style tertentu. */
    private function assertRowHasStyle(string $xml, string $accountNameUpper, string $expectedStyle): void
    {
        $row = $this->findRowContaining($xml, $accountNameUpper);
        $this->assertStringContainsString('ss:StyleID="' . $expectedStyle . '"', $row);
    }

    private function assertRowContainsText(string $xml, string $accountNameUpper, string $needle): void
    {
        $row = $this->findRowContaining($xml, $accountNameUpper);
        $this->assertStringContainsString($needle, $row);
    }

    private function findRowContaining(string $xml, string $needle): string
    {
        $rows = preg_split('/(?=<Row)/', $xml);
        foreach ($rows as $row) {
            if (str_contains($row, $needle)) {
                return $row;
            }
        }

        $this->fail("Tidak ada <Row> yang memuat \"{$needle}\".");
    }

    private function createAccount(string $name, string $group): Account
    {
        $account = Account::query()->create(['name' => $name]);
        $account->forceFill(['account_group' => $group])->save();

        return $account->fresh();
    }

    private function createUser(UserRole $role, ?Account $account = null): User
    {
        static $sequence = 0;
        $sequence++;

        $user = new User([
            'name' => $role->label() . ' Log Export ' . $sequence,
            'email' => "{$role->value}-log-export-{$sequence}@test.local",
            'password' => Hash::make('LogExport!123'),
            'account_id' => $account?->id,
        ]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }
}
