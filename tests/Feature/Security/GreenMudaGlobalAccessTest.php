<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\User;
use App\Support\AccountGroup;
use App\Support\GreenMudaRoster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GreenMudaGlobalAccessTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'green_muda_test',
                'database.connections.green_muda_test' => [
                    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
                'cache.default' => 'array', 'session.driver' => 'array',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_group', 10)->nullable();
            $table->string('logo_path')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->string('survey_team')->nullable();
            $table->string('primary_color')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('account_id');
            $table->timestamps();
            $table->unique(['user_id', 'account_id']);
        });
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id');
            $table->string('state');
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('surveyor_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('schedule_slot_key')->nullable()->unique();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('report_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('account_id');
            $table->date('report_date');
            $table->string('report_category')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'account_id', 'report_date']);
        });
    }

    public function test_roster_contract_has_exact_accounts_groups_and_surveyors(): void
    {
        $rows = GreenMudaRoster::accounts();

        $this->assertCount(31, $rows);
        $this->assertSame(AccountGroup::values(), array_keys(GreenMudaRoster::GROUPS));
        $this->assertCount(7, GreenMudaRoster::SURVEYORS);
        $this->assertCount(31, collect($rows)->pluck('account')->unique());
        $this->assertCount(18, collect($rows)->pluck('admin')->unique());
        $this->assertSame('SRI', collect($rows)->firstWhere('account', 'FURNITURE CIMAHI')['admin']);
        $this->assertSame('LISA', collect($rows)->firstWhere('account', 'MEWAH INTERIOR')['admin']);
        $this->assertSame('HASNI', collect($rows)->firstWhere('account', 'MEDIAN INTERIOR')['admin']);
        $this->assertSame('superadmin@ereport.app', GreenMudaRoster::SUPER_ADMIN['email']);
    }

    public function test_admin_can_select_only_a_membership_account(): void
    {
        $first = DB::table('accounts')->insertGetId(['name' => 'Satu', 'account_group' => 'PC']);
        $second = DB::table('accounts')->insertGetId(['name' => 'Dua', 'account_group' => 'INSIP']);
        $outside = DB::table('accounts')->insertGetId(['name' => 'Luar', 'account_group' => 'PRIBADI']);
        $admin = $this->user(UserRole::Admin, $first);
        DB::table('account_user')->insert([
            ['user_id' => $admin->id, 'account_id' => $first],
            ['user_id' => $admin->id, 'account_id' => $second],
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('X-Account-Id', (string) $second)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.default_account_id', $first)
            ->assertJsonPath('user.active_account_id', $second)
            ->assertJsonCount(2, 'user.accounts');
        $this->withHeader('X-Account-Id', (string) $outside)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_manager_is_global_and_surveyor_is_assignment_only(): void
    {
        $accountA = DB::table('accounts')->insertGetId(['name' => 'A', 'account_group' => 'PC']);
        $accountB = DB::table('accounts')->insertGetId(['name' => 'B', 'account_group' => 'PRIBADI']);
        $manager = $this->user(UserRole::ManagerSurveyor);
        $surveyor = $this->user(UserRole::Surveyor);
        $other = $this->user(UserRole::Surveyor);
        DB::table('surveys')->insert([
            ['account_id' => $accountA, 'state' => Survey::STATE_SCHEDULED, 'surveyor_id' => $surveyor->id],
            ['account_id' => $accountB, 'state' => Survey::STATE_SCHEDULED, 'surveyor_id' => $other->id],
        ]);

        $this->assertSame(2, Survey::query()->visibleTo($manager)->count());
        $this->assertSame(1, Survey::query()->visibleTo($surveyor)->count());
    }

    public function test_attendance_is_unique_per_admin_active_account_and_date(): void
    {
        $first = DB::table('accounts')->insertGetId(['name' => 'Satu', 'account_group' => 'PC']);
        $second = DB::table('accounts')->insertGetId(['name' => 'Dua', 'account_group' => 'INSIP']);
        $admin = $this->user(UserRole::Admin, $first);
        DB::table('account_user')->insert([
            ['user_id' => $admin->id, 'account_id' => $first],
            ['user_id' => $admin->id, 'account_id' => $second],
        ]);
        Sanctum::actingAs($admin);

        $this->withHeader('X-Account-Id', (string) $first)
            ->postJson('/api/v1/report-attendances', ['report_category' => 'ada_wa'])
            ->assertCreated();
        $this->withHeader('X-Account-Id', (string) $second)
            ->postJson('/api/v1/report-attendances', ['report_category' => 'nol_wa'])
            ->assertCreated();
        $this->withHeader('X-Account-Id', (string) $first)
            ->postJson('/api/v1/report-attendances', ['report_category' => 'ada_wa'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('report_attendances', 2);
        $this->assertDatabaseHas('report_attendances', ['user_id' => $admin->id, 'account_id' => $first]);
        $this->assertDatabaseHas('report_attendances', ['user_id' => $admin->id, 'account_id' => $second]);
    }

    public function test_schedule_slot_key_rejects_exact_duplicate_but_allows_other_time(): void
    {
        $account = DB::table('accounts')->insertGetId(['name' => 'A', 'account_group' => 'PC']);
        DB::table('surveys')->insert([
            'account_id' => $account, 'state' => Survey::STATE_SCHEDULED,
            'surveyor_id' => 9, 'scheduled_at' => '2026-10-23 09:00:00',
            'schedule_slot_key' => '9|2026-10-23 09:00:00',
        ]);
        DB::table('surveys')->insert([
            'account_id' => $account, 'state' => Survey::STATE_SCHEDULED,
            'surveyor_id' => 9, 'scheduled_at' => '2026-10-23 10:00:00',
            'schedule_slot_key' => '9|2026-10-23 10:00:00',
        ]);

        $this->expectException(QueryException::class);
        DB::table('surveys')->insert([
            'account_id' => $account, 'state' => Survey::STATE_IN_PROGRESS,
            'surveyor_id' => 9, 'scheduled_at' => '2026-10-23 09:00:00',
            'schedule_slot_key' => '9|2026-10-23 09:00:00',
        ]);
    }

    private function user(UserRole $role, ?int $accountId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role->value.' '.uniqid(),
            'email' => uniqid().'@example.test',
            'password' => 'hash', 'role' => $role->value, 'account_id' => $accountId,
        ]);

        return User::query()->findOrFail($id);
    }
}
