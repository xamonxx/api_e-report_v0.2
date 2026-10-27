<?php

namespace Tests\Feature\Security;

use App\Console\Commands\SyncGreenMudaRoster;
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
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $sortOrder = 1;
        foreach (array_keys(GreenMudaRoster::GROUPS) as $code) {
            DB::table('account_groups')->insert([
                'code' => $code,
                'name' => $code,
                'sort_order' => $sortOrder++,
                'is_active' => true,
            ]);
        }
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_group', 10)->nullable();
            $table->foreignId('account_group_id')->nullable()->constrained('account_groups')->nullOnDelete();
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
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable();
            $table->string('account_group', 10)->nullable();
            $table->foreignId('account_group_id')->nullable();
            $table->date('consultation_date')->nullable();
            $table->timestamps();
        });
    }

    public function test_roster_contract_has_exact_accounts_groups_and_surveyors(): void
    {
        $rows = GreenMudaRoster::accounts();

        $this->assertCount(33, $rows);
        $this->assertSame(AccountGroup::values(), array_keys(GreenMudaRoster::GROUPS));
        $this->assertCount(7, GreenMudaRoster::SURVEYORS);
        $this->assertCount(33, collect($rows)->pluck('account')->unique());
        $this->assertCount(18, collect($rows)->pluck('admin')->unique());
        $this->assertSame('SRI', collect($rows)->firstWhere('account', 'FURNITURE CIMAHI')['admin']);
        $this->assertSame('LISA', collect($rows)->firstWhere('account', 'MEWAH INTERIOR')['admin']);
        $this->assertSame('HASNI', collect($rows)->firstWhere('account', 'MEDIAN INTERIOR')['admin']);
        $this->assertSame('HASNI', collect($rows)->firstWhere('account', 'INTERIOR KITA')['admin']);
        $this->assertSame('HASNI', collect($rows)->firstWhere('account', 'ALLFAR INTERIOR')['admin']);
        $this->assertSame('superadmin@ereport.app', GreenMudaRoster::SUPER_ADMIN['email']);
    }

    public function test_roster_admin_resolver_prefers_active_canonical_users_over_historical_collisions(): void
    {
        foreach (['OMEN', 'ANO', 'DIAN G', 'AGUNG', 'UNIR'] as $index => $displayName) {
            $defaultId = DB::table('accounts')->insertGetId([
                'name' => "Default {$displayName}",
                'account_group' => 'PC',
            ]);
            $secondaryId = DB::table('accounts')->insertGetId([
                'name' => "Secondary {$displayName}",
                'account_group' => 'PC',
            ]);

            $historical = $this->user(UserRole::Admin, $defaultId);
            $historical->name = $displayName;
            $historical->email = "historical-{$index}@example.test";
            $historical->save();
            $historical->delete();

            $unrelated = $this->user(UserRole::Admin, $defaultId);
            $unrelated->name = "UNRELATED {$index}";
            $unrelated->save();

            $canonical = $this->user(UserRole::Admin, $secondaryId);
            $canonical->name = match ($displayName) {
                'ANO' => 'ANOTHER NAME',
                'DIAN G' => 'DIAN',
                default => $displayName,
            };
            $canonical->email = $displayName === 'ANO'
                ? 'ano.admin@ereport.app'
                : "canonical-{$index}@example.test";
            $canonical->save();

            DB::table('account_user')->insert([
                'user_id' => $canonical->id,
                'account_id' => $secondaryId,
            ]);

            $command = new SyncGreenMudaRoster;
            $method = new \ReflectionMethod($command, 'activeAdminCandidates');
            $candidates = $method->invoke($command, $displayName);

            $this->assertSame($canonical->id, $candidates->first()?->id, "Resolver salah untuk {$displayName}");
            $this->assertFalse($candidates->contains('id', $historical->id));
        }
    }

    public function test_roster_admin_resolver_does_not_rename_unrelated_single_account_user(): void
    {
        $accountId = DB::table('accounts')->insertGetId([
            'name' => 'Target Account',
            'account_group' => 'PC',
        ]);
        $unrelated = $this->user(UserRole::Admin, $accountId);
        $unrelated->name = 'UNRELATED USER';
        $unrelated->email = 'unrelated@example.test';
        $unrelated->save();

        $command = new SyncGreenMudaRoster;
        $method = new \ReflectionMethod($command, 'activeAdminCandidates');
        $candidates = $method->invoke($command, 'HASNI');

        $this->assertCount(0, $candidates);
        $this->assertSame('UNRELATED USER', $unrelated->fresh()->name);
    }

    public function test_roster_admin_resolver_rejects_duplicate_active_identities(): void
    {
        foreach ([1, 2] as $suffix) {
            $user = $this->user(UserRole::Admin);
            $user->name = 'OMEN';
            $user->email = "omen-duplicate-{$suffix}@example.test";
            $user->save();
        }

        $command = new SyncGreenMudaRoster;
        $method = new \ReflectionMethod($command, 'activeAdminCandidates');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Admin OMEN ambigu');

        $method->invoke($command, 'OMEN');
    }

    public function test_roster_admin_resolver_selects_unique_canonical_email_among_duplicate_names(): void
    {
        $duplicate = $this->user(UserRole::Admin);
        $duplicate->name = 'OMEN';
        $duplicate->email = 'omen-other@example.test';
        $duplicate->save();

        $canonical = $this->user(UserRole::Admin);
        $canonical->name = 'OMEN';
        $canonical->email = 'omen.admin@ereport.app';
        $canonical->save();

        $command = new SyncGreenMudaRoster;
        $method = new \ReflectionMethod($command, 'activeAdminCandidates');
        $candidates = $method->invoke($command, 'OMEN');

        $this->assertCount(1, $candidates);
        $this->assertSame($canonical->id, $candidates->first()?->id);
    }

    public function test_roster_sync_migrates_consultations_since_specified_date_to_new_account_group(): void
    {
        $pcGroup = DB::table('account_groups')->where('code', 'PC')->first();
        $account = DB::table('accounts')->insertGetId([
            'name' => 'PORTFOLIO OLD',
            'account_group' => 'A',
            'account_group_id' => null,
        ]);

        $oldId = DB::table('consultations')->insertGetId([
            'account_id' => $account,
            'account_group' => 'A',
            'account_group_id' => null,
            'consultation_date' => '2026-10-20',
        ]);
        $newId = DB::table('consultations')->insertGetId([
            'account_id' => $account,
            'account_group' => 'A',
            'account_group_id' => null,
            'consultation_date' => '2026-10-21',
        ]);

        DB::table('accounts')->where('id', $account)->update([
            'account_group' => 'PC',
            'account_group_id' => $pcGroup->id,
        ]);

        $command = new SyncGreenMudaRoster;
        $method = new \ReflectionMethod($command, 'syncConsultations');
        $affected = $method->invoke($command, '2026-10-21');

        $this->assertSame(1, $affected);
        $this->assertSame('A', DB::table('consultations')->where('id', $oldId)->value('account_group'));
        $this->assertNull(DB::table('consultations')->where('id', $oldId)->value('account_group_id'));
        $this->assertSame('PC', DB::table('consultations')->where('id', $newId)->value('account_group'));
        $this->assertSame($pcGroup->id, DB::table('consultations')->where('id', $newId)->value('account_group_id'));
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
