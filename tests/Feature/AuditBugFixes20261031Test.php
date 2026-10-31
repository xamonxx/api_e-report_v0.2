<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationImport;
use App\Models\NeedsCategory;
use App\Models\Reminder;
use App\Models\StatusCategory;
use App\Models\User;
use App\Services\ConsultationImportService;
use App\Support\WilayahNormalizer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi audit bug 2026-10-31 (lihat Change-Log): scope reminder per role,
 * cek telepon duplikat admin, import memakai akun aktif, serialisasi tanggal,
 * fallback akun aktif, index kecamatan, dan guard seeder.
 */
class AuditBugFixes20261031Test extends TestCase
{
    use RefreshDatabase;

    private Account $accountA;

    private Account $accountB;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountA = Account::query()->create(['name' => 'Akun Audit A']);
        $this->accountB = Account::query()->create(['name' => 'Akun Audit B']);
        NeedsCategory::query()->firstOrCreate(['name' => 'Kitchen Set Audit']);
        $this->statusId = StatusCategory::query()->create([
            'name' => 'Status Audit',
            'color' => '#737c7f',
            'sort_order' => 99,
        ])->id;
    }

    public function test_surveyor_tidak_melihat_dan_tidak_bisa_membersihkan_reminder_admin(): void
    {
        $admin = $this->makeUser(UserRole::Admin, $this->accountA->id);
        $consultation = $this->makeConsultation($this->accountA, '081234567801');
        $reminder = Reminder::query()->create([
            'consultation_id' => $consultation->id,
            'user_id' => $admin->id,
            'creator_id' => $admin->id,
            'remind_at' => now()->addMinutes(5),
            'message' => 'Telepon ulang klien',
            'is_read' => false,
        ]);

        foreach ([UserRole::Surveyor, UserRole::ManagerSurveyor] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $this->getJson('/api/v1/notifications/summary')
                ->assertOk()
                ->assertJsonPath('upcoming_reminders', 0)
                ->assertJsonCount(0, 'reminders');

            $this->deleteJson('/api/v1/notifications/clear')->assertOk();
            $this->assertFalse($reminder->fresh()->is_read);
        }

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/notifications/summary')
            ->assertOk()
            ->assertJsonPath('upcoming_reminders', 1);
    }

    public function test_clear_all_super_admin_tidak_menandai_reminder_admin_lain(): void
    {
        $admin = $this->makeUser(UserRole::Admin, $this->accountA->id);
        $consultation = $this->makeConsultation($this->accountA, '081234567802');
        $reminder = Reminder::query()->create([
            'consultation_id' => $consultation->id,
            'user_id' => $admin->id,
            'creator_id' => $admin->id,
            'remind_at' => now()->addMinutes(5),
            'message' => 'Milik admin',
            'is_read' => false,
        ]);

        Sanctum::actingAs($this->makeUser(UserRole::SuperAdmin));
        $this->deleteJson('/api/v1/notifications/clear')->assertOk();

        $this->assertFalse($reminder->fresh()->is_read);
    }

    public function test_admin_tidak_bisa_memakai_account_id_asing_untuk_cek_duplikat_telepon(): void
    {
        $foreign = $this->makeConsultation($this->accountB, '081234567803');
        Sanctum::actingAs($this->makeUser(UserRole::Admin, $this->accountA->id));

        // Nomor yang sama di akun lain bukan duplikat untuk akun admin, dan
        // pesan error tidak boleh membocorkan ID lead akun lain.
        $response = $this->postJson('/api/v1/consultations', $this->payload('081234567803', $this->accountB->id));
        $response->assertCreated();
        $this->assertStringNotContainsString((string) $foreign->consultation_id, $response->getContent());
        $this->assertSame($this->accountA->id, (int) $response->json('data.account_id'));

        // Duplikat di akunnya sendiri tetap ditolak walau account_id diisi akun lain.
        $this->postJson('/api/v1/consultations', $this->payload('081234567803', $this->accountB->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_import_antrean_memakai_akun_aktif_saat_upload(): void
    {
        Storage::fake(config('filesystems.default'));
        $admin = $this->makeUser(UserRole::Admin, $this->accountA->id);
        $admin->accounts()->attach([$this->accountA->id, $this->accountB->id]);

        Storage::put('imports/consultations/audit.csv', "nama,telepon\nKlien Import Audit,081234567804\n");
        $import = ConsultationImport::query()->create([
            'user_id' => $admin->id,
            'account_id' => $this->accountB->id,
            'original_name' => 'audit.csv',
            'stored_path' => 'imports/consultations/audit.csv',
            'status' => 'queued',
        ]);

        // Dipanggil di luar request, sama seperti worker antrean.
        app(ConsultationImportService::class)->process($import);

        $this->assertSame('completed', $import->fresh()->status, (string) $import->fresh()->error_preview);
        $this->assertDatabaseHas('consultations', [
            'client_name' => 'Klien Import Audit',
            'account_id' => $this->accountB->id,
        ]);
    }

    public function test_tanggal_diserialisasi_sebagai_tanggal_lokal_tanpa_jam(): void
    {
        $consultation = $this->makeConsultation($this->accountA, '081234567805', '2026-10-30');

        $this->assertSame('2026-10-30', $consultation->fresh()->toArray()['consultation_date']);
    }

    public function test_admin_tanpa_akun_primer_tetap_masuk_ke_akun_pivot(): void
    {
        $admin = $this->makeUser(UserRole::Admin, null);
        $admin->accounts()->attach($this->accountB->id);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.active_account_id', $this->accountB->id);
    }

    public function test_kecamatan_tanpa_spasi_tidak_tertukar_dengan_varian_berspasi(): void
    {
        foreach (['Tompobulu', 'Tompo Bulu', 'Sumberjaya', 'Sumber Jaya', 'Pakisaji', 'Pakis Aji'] as $name) {
            $this->assertSame(
                ['value' => $name, 'matched' => true],
                WilayahNormalizer::canonicalDistrict($name),
                $name
            );
        }
    }

    public function test_database_seeder_menolak_jalan_di_produksi(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            (new DatabaseSeeder())->run();
        } finally {
            $this->app['env'] = 'testing';
            $this->assertDatabaseHas('accounts', ['id' => $this->accountA->id]);
        }
    }

    private function payload(string $phone, int $accountId): array
    {
        return [
            'client_name' => 'Klien Audit',
            'phone' => $phone,
            'account_id' => $accountId,
            'status_category_id' => $this->statusId,
        ];
    }

    private function makeConsultation(Account $account, string $phone, string $date = '2026-10-30'): Consultation
    {
        $consultation = new Consultation();
        $consultation->forceFill([
            'consultation_id' => 'AUD.'.substr($phone, -6),
            'client_name' => 'Klien Audit '.$phone,
            'phone' => $phone,
            'account_id' => $account->id,
            'status_category_id' => $this->statusId,
            'consultation_date' => $date,
        ])->save();

        return $consultation;
    }

    private function makeUser(UserRole $role, ?int $accountId = null): User
    {
        $user = new User([
            'name' => $role->label().' Audit',
            'email' => $role->value.'-'.uniqid().'@audit.test',
            'password' => Hash::make('AuditFix!123'),
        ]);
        $user->role = $role;
        $user->account_id = $accountId;
        $user->save();

        return $user->fresh();
    }
}
