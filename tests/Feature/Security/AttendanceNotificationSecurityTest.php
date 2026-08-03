<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AttendanceNotification;
use App\Models\ReportAttendance;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AttendanceNotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_attendance_notifies_only_super_admins_once(): void
    {
        $account = $this->createAccount('Audit Account');
        $admin = $this->createUser(UserRole::Admin, $account, 'admin@audit.test');
        $superAdminA = $this->createUser(UserRole::SuperAdmin, null, 'super-a@audit.test');
        $superAdminB = $this->createUser(UserRole::SuperAdmin, null, 'super-b@audit.test');
        $surveyor = $this->createUser(UserRole::Surveyor, null, 'surveyor@audit.test');

        $this->mock(WebPushService::class, function (MockInterface $mock) use ($superAdminA, $superAdminB): void {
            $mock->shouldReceive('sendToUsers')
                ->once()
                ->withArgs(function (array $userIds, array $payload) use ($superAdminA, $superAdminB): bool {
                    sort($userIds);
                    $expected = [$superAdminA->id, $superAdminB->id];
                    sort($expected);

                    return $userIds === $expected
                        && $payload['type'] === 'attendance'
                        && $payload['url'] === '/report-attendances'
                        && str_contains($payload['body'], 'Audit Account');
                });
        });

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/report-attendances', ['report_category' => 'ada_wa'])
            ->assertCreated();

        $attendance = ReportAttendance::query()->sole();
        $this->assertDatabaseHas('attendance_notifications', [
            'user_id' => $superAdminA->id,
            'report_attendance_id' => $attendance->id,
        ]);
        $this->assertDatabaseHas('attendance_notifications', [
            'user_id' => $superAdminB->id,
            'report_attendance_id' => $attendance->id,
        ]);
        $this->assertDatabaseMissing('attendance_notifications', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('attendance_notifications', ['user_id' => $surveyor->id]);

        $this->postJson('/api/v1/report-attendances', ['report_category' => 'ada_wa'])
            ->assertUnprocessable();
        $this->assertSame(2, AttendanceNotification::query()->count());
    }

    public function test_attendance_is_saved_when_push_delivery_throws(): void
    {
        $account = $this->createAccount('Failure Isolation');
        $admin = $this->createUser(UserRole::Admin, $account, 'admin-failure@audit.test');
        $superAdmin = $this->createUser(UserRole::SuperAdmin, null, 'super-failure@audit.test');

        $this->mock(WebPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUsers')
                ->once()
                ->andThrow(new RuntimeException('Synthetic push failure'));
        });

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/report-attendances', ['report_category' => 'nol_wa'])
            ->assertCreated();

        $this->assertDatabaseHas('report_attendances', ['user_id' => $admin->id]);
        $this->assertDatabaseHas('attendance_notifications', ['user_id' => $superAdmin->id]);
    }

    public function test_attendance_notifications_are_hidden_from_other_roles_and_owners(): void
    {
        $account = $this->createAccount('Visibility Audit');
        $admin = $this->createUser(UserRole::Admin, $account, 'admin-visibility@audit.test');
        $superAdminA = $this->createUser(UserRole::SuperAdmin, null, 'super-owner@audit.test');
        $superAdminB = $this->createUser(UserRole::SuperAdmin, null, 'super-other@audit.test');
        $attendance = ReportAttendance::query()->create([
            'user_id' => $admin->id,
            'account_id' => $account->id,
            'report_date' => now()->toDateString(),
            'report_category' => 'ada_wa',
        ]);
        $notification = AttendanceNotification::query()->create([
            'user_id' => $superAdminA->id,
            'report_attendance_id' => $attendance->id,
            'title' => 'Absensi admin masuk',
            'message' => 'Payload audit',
            'admin_name' => $admin->name,
            'account_name' => $account->name,
            'report_date' => now()->toDateString(),
            'report_category' => 'ada_wa',
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/notifications/summary')
            ->assertOk()
            ->assertJsonPath('unread_attendances', 0)
            ->assertJsonCount(0, 'attendances');
        $this->patchJson("/api/v1/notifications/attendances/{$notification->id}/read")
            ->assertForbidden();

        Sanctum::actingAs($superAdminB);
        $this->deleteJson("/api/v1/notifications/attendances/{$notification->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('attendance_notifications', ['id' => $notification->id]);
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
