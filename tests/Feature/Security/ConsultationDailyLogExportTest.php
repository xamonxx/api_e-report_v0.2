<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
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
