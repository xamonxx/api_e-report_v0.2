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
 * Detail proyek (product_details) selalu disimpan apa pun kategorinya; hanya
 * wajib diisi bila kategori "Lain-Lain" dipilih. Dulu detail dibuang diam-diam
 * bila "Lain-Lain" tidak dipilih.
 */
class ConsultationProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private int $kitchenId;

    private int $otherId;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = new User([
            'name' => 'Super Admin Detail',
            'email' => 'detail-'.uniqid().'@test.local',
            'password' => Hash::make('Password!123'),
        ]);
        $user->role = UserRole::SuperAdmin;
        $user->save();
        Sanctum::actingAs($user->fresh());

        $this->account = Account::query()->create(['name' => 'Akun Uji Detail']);
        $this->kitchenId = NeedsCategory::query()->firstOrCreate(['name' => 'Kitchen Set Uji Detail'])->id;
        $this->otherId = NeedsCategory::query()->firstOrCreate(['name' => NeedsCategory::OTHER_OPTION_LABEL])->id;
        $this->statusId = StatusCategory::query()->create([
            'name' => 'Status Uji Detail',
            'color' => '#737c7f',
            'sort_order' => 99,
        ])->id;
    }

    public function test_detail_disimpan_walau_kategori_bukan_lain_lain(): void
    {
        $this->postJson('/api/v1/consultations', $this->payload([
            'needs_category_ids' => [$this->kitchenId],
            'product_details' => 'Kitchen set L 3 m, HPL putih',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.product_details', 'Kitchen set L 3 m, HPL putih');

        $this->assertDatabaseHas('consultations', ['product_details' => 'Kitchen set L 3 m, HPL putih']);
    }

    public function test_detail_tetap_wajib_bila_lain_lain_dipilih(): void
    {
        $this->postJson('/api/v1/consultations', $this->payload([
            'needs_category_ids' => [$this->otherId],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_details']);
    }

    public function test_detail_tidak_hilang_saat_update_tanpa_lain_lain(): void
    {
        $id = $this->postJson('/api/v1/consultations', $this->payload([
            'needs_category_ids' => [$this->kitchenId],
            'product_details' => 'Awal',
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/consultations/{$id}", $this->payload([
            'needs_category_ids' => [$this->kitchenId],
            'product_details' => 'Lemari 2 pintu',
        ]))
            ->assertOk()
            ->assertJsonPath('data.product_details', 'Lemari 2 pintu');
    }

    private function payload(array $overrides): array
    {
        return array_merge([
            'client_name' => 'Klien Uji Detail',
            'phone' => '081234567891',
            'account_id' => $this->account->id,
            'status_category_id' => $this->statusId,
        ], $overrides);
    }
}
