<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Services\ConsultationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Encoding CSV (audit mojibake 2026-11-03).
 *
 * Export: UTF-16LE + BOM + tab, karena UTF-8 BOM + `sep=,` terbukti dibaca
 * Excel id-ID sebagai Windows-1252. Import: file ANSI/UTF-16 dari Excel wajib
 * dinormalkan ke UTF-8 sebelum masuk kolom utf8mb4.
 */
class CsvEncodingTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'José — Café';

    private Account $account;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::query()->create(['name' => 'Akun Encoding']);
        NeedsCategory::query()->firstOrCreate(['name' => 'Kitchen Set Encoding']);
        $this->statusId = StatusCategory::query()->create([
            'name' => 'Status Encoding',
            'color' => '#737c7f',
            'sort_order' => 99,
        ])->id;
    }

    public function test_export_csv_ditulis_utf16le_dengan_bom_dan_tab(): void
    {
        $consultation = new Consultation();
        $consultation->forceFill([
            'consultation_id' => 'ENC.000001',
            'client_name' => self::NAME,
            'phone' => '081234567890',
            'account_id' => $this->account->id,
            'status_category_id' => $this->statusId,
            'consultation_date' => '2026-11-03',
        ])->save();
        Sanctum::actingAs($this->makeUser(UserRole::SuperAdmin));

        $response = $this->get('/api/v1/export/csv');

        $response->assertOk();
        $this->assertStringContainsString('charset=UTF-16LE', (string) $response->headers->get('Content-Type'));

        $raw = $response->streamedContent();
        $this->assertStringStartsWith("\xFF\xFE", $raw);

        $text = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        $this->assertStringStartsWith("\"ID Konsultasi\"\t\"Nama Klien\"\t", $text);
        $this->assertStringContainsString("ENC.000001\t\"".self::NAME."\"\t", $text);
        $this->assertStringNotContainsString('sep=', $text);
    }

    public function test_import_csv_ansi_windows_1252_dinormalkan_ke_utf8(): void
    {
        $content = mb_convert_encoding("nama,telepon\nJosé Café,081234567891\n", 'Windows-1252', 'UTF-8');

        $this->runImport($content);

        $this->assertDatabaseHas('consultations', ['client_name' => 'José Café']);
    }

    public function test_import_csv_utf16le_bom_tab_dari_export_sendiri(): void
    {
        $content = "\xFF\xFE".mb_convert_encoding("nama\ttelepon\n".self::NAME."\t081234567892\n", 'UTF-16LE', 'UTF-8');

        $this->runImport($content);

        $this->assertDatabaseHas('consultations', ['client_name' => self::NAME]);
    }

    public function test_import_csv_utf8_bom_tetap_terbaca(): void
    {
        $this->runImport("\xEF\xBB\xBFnama,telepon\n".self::NAME.",081234567893\n");

        $this->assertDatabaseHas('consultations', ['client_name' => self::NAME]);
    }

    private function runImport(string $content): void
    {
        Storage::fake(config('filesystems.default'));
        $admin = $this->makeUser(UserRole::Admin, $this->account->id);

        Storage::put('imports/consultations/encoding.csv', $content);
        $import = ConsultationImport::query()->create([
            'user_id' => $admin->id,
            'account_id' => $this->account->id,
            'original_name' => 'encoding.csv',
            'stored_path' => 'imports/consultations/encoding.csv',
            'status' => 'queued',
        ]);

        app(ConsultationImportService::class)->process($import);

        $this->assertSame('completed', $import->fresh()->status, (string) $import->fresh()->error_preview);
        $this->assertSame(1, (int) $import->fresh()->success_count, (string) $import->fresh()->error_preview);
    }

    private function makeUser(UserRole $role, ?int $accountId = null): User
    {
        $user = new User([
            'name' => $role->label().' Encoding',
            'email' => $role->value.'-'.uniqid().'@encoding.test',
            'password' => Hash::make('EncodingFix!123'),
        ]);
        $user->role = $role;
        $user->account_id = $accountId;
        $user->save();

        return $user->fresh();
    }
}
