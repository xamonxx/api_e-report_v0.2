<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicAccountGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = new User([
            'name' => 'Super Admin Grup',
            'email' => 'superadmin-groups@test.local',
            'password' => Hash::make('Password!123'),
        ]);
        $this->superAdmin->role = UserRole::SuperAdmin;
        $this->superAdmin->save();
        $this->superAdmin = $this->superAdmin->fresh();
        Sanctum::actingAs($this->superAdmin);
    }

    public function test_account_move_preserves_old_snapshot_and_new_data_uses_new_group(): void
    {
        $oldGroup = AccountGroup::query()->where('code', 'PC')->firstOrFail();
        $newGroup = $this->postJson('/api/v1/master-data/account-groups', ['name' => 'Grup Baru'])
            ->assertCreated()
            ->json('data');

        $account = Account::query()->create([
            'name' => 'Akun Pindah Grup',
            'account_group_id' => $oldGroup->id,
        ]);
        $account->forceFill(['account_group' => $oldGroup->code])->save();

        $oldConsultation = Consultation::query()->create([
            'consultation_id' => 'GRP.OLD.0001',
            'client_name' => 'Data Lama',
            'account_id' => $account->id,
            'account_group_id' => $oldGroup->id,
            'account_group' => $oldGroup->code,
            'created_by' => $this->superAdmin->id,
            'consultation_date' => now()->toDateString(),
        ]);

        $this->putJson("/api/v1/accounts/{$account->id}", [
            'name' => $account->name,
            'account_group' => $newGroup['code'],
        ])->assertOk()
            ->assertJsonPath('group_change.from.code', 'PC')
            ->assertJsonPath('group_change.to.code', $newGroup['code']);

        $this->assertDatabaseHas('consultations', [
            'id' => $oldConsultation->id,
            'account_group_id' => $oldGroup->id,
            'account_group' => 'PC',
        ]);

        $movedAccount = $account->fresh();
        $newConsultation = Consultation::query()->create([
            'consultation_id' => 'GRP.NEW.0001',
            'client_name' => 'Data Baru',
            'account_id' => $movedAccount->id,
            'account_group_id' => $movedAccount->account_group_id,
            'account_group' => $movedAccount->account_group,
            'created_by' => $this->superAdmin->id,
            'consultation_date' => now()->toDateString(),
        ]);

        $this->assertSame($newGroup['id'], $newConsultation->account_group_id);
        $this->assertSame(1, $movedAccount->currentGroupConsultations()->count());
        $this->assertSame(1, $movedAccount->lead_count);
        $this->assertSame(0.0, $movedAccount->conversion_rate);
        $this->assertContains('X-Account-Id', config('cors.allowed_headers', []));
        $this->getJson('/api/v1/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.consultations_count', 1);
    }

    public function test_archive_requires_empty_group_and_restore_makes_it_active_again(): void
    {
        $group = AccountGroup::query()->where('code', 'PC')->firstOrFail();
        $account = Account::query()->create([
            'name' => 'Akun Penghambat Arsip',
            'account_group_id' => $group->id,
        ]);
        $account->forceFill(['account_group' => $group->code])->save();

        $this->patchJson("/api/v1/master-data/account-groups/{$group->id}/status", ['is_active' => false])
            ->assertUnprocessable();

        $other = AccountGroup::query()->where('code', 'INSIP')->firstOrFail();
        $account->forceFill(['account_group_id' => $other->id, 'account_group' => $other->code])->save();

        $this->patchJson("/api/v1/master-data/account-groups/{$group->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/master-data/account-groups')
            ->assertOk()
            ->assertJsonFragment(['code' => 'PC', 'is_active' => false]);

        $this->patchJson("/api/v1/master-data/account-groups/{$group->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_rename_keeps_stable_identity_and_duplicate_name_is_rejected(): void
    {
        $group = AccountGroup::query()->where('code', 'PC')->firstOrFail();

        $this->putJson("/api/v1/master-data/account-groups/{$group->id}", ['name' => 'Putra Corporation'])
            ->assertOk()
            ->assertJsonPath('data.id', $group->id)
            ->assertJsonPath('data.code', 'PC')
            ->assertJsonPath('data.name', 'Putra Corporation');

        $this->postJson('/api/v1/master-data/account-groups', ['name' => 'Putra Corporation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}
