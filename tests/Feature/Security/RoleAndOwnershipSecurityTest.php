<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAndOwnershipSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_routes_reject_unauthenticated_requests(): void
    {
        $this->getJson('/api/v1/accounts')->assertUnauthorized();
        $this->getJson('/api/v1/consultations')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/summary')->assertUnauthorized();
        $this->postJson('/api/v1/report-attendances', ['report_category' => 'ada_wa'])
            ->assertUnauthorized();
    }

    public function test_role_middleware_blocks_function_level_escalation(): void
    {
        $account = $this->createAccount('Role Matrix');
        $admin = $this->createUser(UserRole::Admin, $account, 'admin-role@audit.test');
        $surveyor = $this->createUser(UserRole::Surveyor, null, 'surveyor-role@audit.test');
        $manager = $this->createUser(UserRole::ManagerSurveyor, null, 'manager-role@audit.test');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/accounts')->assertForbidden();
        $this->postJson('/api/v1/report-attendances/upsert-by-super-admin', [
            'user_id' => $admin->id,
            'report_date' => now()->toDateString(),
            'report_category' => 'ada_wa',
        ])->assertForbidden();

        Sanctum::actingAs($surveyor);
        $this->getJson('/api/v1/dashboard')->assertForbidden();
        $this->getJson('/api/v1/master-data/users')->assertForbidden();

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/consultations')->assertForbidden();
    }

    public function test_admin_cannot_read_or_mutate_another_accounts_consultation(): void
    {
        $accountA = $this->createAccount('Account A');
        $accountB = $this->createAccount('Account B');
        $adminA = $this->createUser(UserRole::Admin, $accountA, 'admin-a@audit.test');
        $adminB = $this->createUser(UserRole::Admin, $accountB, 'admin-b@audit.test');
        $consultationB = Consultation::query()->create([
            'consultation_id' => '02.2608.0001',
            'client_name' => 'Synthetic Client B',
            'account_id' => $accountB->id,
            'created_by' => $adminB->id,
            'consultation_date' => now()->toDateString(),
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson("/api/v1/consultations/{$consultationB->id}")
            ->assertForbidden();
        $this->postJson("/api/v1/consultations/{$consultationB->id}/notes", [
            'body' => 'Catatan lintas akun harus ditolak.',
        ])->assertForbidden();
        $this->assertDatabaseMissing('consultation_notes', [
            'consultation_id' => $consultationB->id,
            'user_id' => $adminA->id,
        ]);
    }

    public function test_admin_attendance_list_contains_only_the_current_admin(): void
    {
        $account = $this->createAccount('Attendance Scope');
        $adminA = $this->createUser(UserRole::Admin, $account, 'attendance-a@audit.test');
        $this->createUser(UserRole::Admin, $account, 'attendance-b@audit.test');

        Sanctum::actingAs($adminA);

        $this->getJson('/api/v1/report-attendances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admin_id', $adminA->id);
    }

    public function test_invalid_attendance_date_returns_validation_error_instead_of_server_error(): void
    {
        $account = $this->createAccount('Attendance Validation');
        $admin = $this->createUser(UserRole::Admin, $account, 'attendance-date@audit.test');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/report-attendances?date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    private function createAccount(string $name): Account
    {
        return Account::query()->create(['name' => $name]);
    }

    private function createUser(UserRole $role, ?Account $account, string $email): User
    {
        $user = new User([
            'name' => $role->label().' Audit',
            'email' => $email,
            'password' => Hash::make('AuditPassword!123'),
            'account_id' => $account?->id,
        ]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }
}
