<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Temuan tes alur 2026-10-31:
 * - Pratinjau ID lead dulu menaikkan sequence, jadi ID yang tampil di form
 *   (0007) beda dari yang tersimpan (0008) dan nomor terbuang.
 * - Detail lead selalu "Dibuat oleh Sistem" karena relasi creator tidak dimuat.
 */
class ConsultationIdPreviewAndCreatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User([
            'name' => 'Super Admin Pratinjau',
            'email' => 'pratinjau-'.uniqid().'@test.local',
            'password' => Hash::make('Password!123'),
        ]);
        $this->user->role = UserRole::SuperAdmin;
        $this->user->save();
        Sanctum::actingAs($this->user->fresh());

        $this->account = Account::query()->create(['name' => 'Akun Uji Pratinjau']);
    }

    public function test_pratinjau_berulang_tidak_membakar_nomor_dan_sama_dengan_id_tersimpan(): void
    {
        $first = $this->getJson('/api/v1/consultations/id-preview?account_id='.$this->account->id)
            ->assertOk()
            ->json('consultation_id');
        $second = $this->getJson('/api/v1/consultations/id-preview?account_id='.$this->account->id)
            ->assertOk()
            ->json('consultation_id');

        $this->assertSame($first, $second);
        $this->assertStringEndsWith('.0001', $first);

        $saved = $this->postJson('/api/v1/consultations', $this->payload())
            ->assertCreated()
            ->json('data.consultation_id');

        $this->assertSame($first, $saved);

        $next = $this->getJson('/api/v1/consultations/id-preview?account_id='.$this->account->id)
            ->assertOk()
            ->json('consultation_id');
        $this->assertStringEndsWith('.0002', $next);
    }

    public function test_detail_lead_memuat_nama_pembuat(): void
    {
        $id = $this->postJson('/api/v1/consultations', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->getJson("/api/v1/consultations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonPath('data.creator.name', 'Super Admin Pratinjau');
    }

    private function payload(): array
    {
        return [
            'client_name' => 'Klien Uji Pratinjau',
            'phone' => '081234567891',
            'account_id' => $this->account->id,
            'needs_category_ids' => [NeedsCategory::query()->firstOrCreate(['name' => 'Kategori Uji Pratinjau'])->id],
            'status_category_id' => StatusCategory::query()->create([
                'name' => 'Status Uji Pratinjau '.uniqid(),
                'color' => '#737c7f',
                'sort_order' => 99,
            ])->id,
        ];
    }
}
