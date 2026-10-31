<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/surveys/counts: jumlah per state + today/overdue untuk tab /surveys.
 *
 * Skema SQLite :memory: dibuat manual (pola GreenMudaGlobalAccessTest) karena
 * migrasi operasional tidak bisa membangun database kosong.
 */
class SurveyCountsTest extends TestCase
{
    private int $accountA;

    private int $accountB;

    private User $superAdmin;

    private User $manager;

    private User $surveyorOne;

    private User $surveyorTwo;

    private User $adminA;

    private User $adminB;

    /** @var array<string, int> */
    private array $ids = [];

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'survey_counts_test',
                'database.connections.survey_counts_test' => [
                    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
                'cache.default' => 'array', 'session.driver' => 'array',
                'broadcasting.default' => 'null', 'queue.default' => 'sync',
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        // 06:00 WIB = 23:00 UTC kemarin: tanggal WIB berbeda dengan tanggal UTC.
        $this->travelTo(Carbon::parse('2026-10-31 06:00:00', 'Asia/Jakarta'));

        $this->createFixtureSchema();
        $this->seedFixtures();
    }

    public function test_super_admin_and_manager_see_all_states_today_and_overdue(): void
    {
        $expected = [
            'by_state' => [
                'requested' => 1,
                'scheduled' => 4,
                'in_progress' => 1,
                'completed' => 1,
                'cancelled' => 1,
            ],
            'today' => 2,
            'overdue' => 1,
        ];

        foreach ([$this->superAdmin, $this->manager] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/surveys/counts')
                ->assertOk()
                ->assertExactJson(['data' => $expected]);
        }
    }

    public function test_surveyor_only_counts_own_assignments(): void
    {
        Sanctum::actingAs($this->surveyorOne);
        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 0, 'scheduled' => 3, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0],
            'today' => 1,
            'overdue' => 1,
        ]]);

        Sanctum::actingAs($this->surveyorTwo);
        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 0, 'scheduled' => 1, 'in_progress' => 1, 'completed' => 1, 'cancelled' => 0],
            // Jadwal 00:30 WIB hari ini tetap "today" (di UTC jatuh ke kemarin).
            'today' => 1,
            'overdue' => 0,
        ]]);
    }

    public function test_admin_counts_follow_visible_to_active_account_and_requester(): void
    {
        Sanctum::actingAs($this->adminA);
        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 1, 'scheduled' => 2, 'in_progress' => 0, 'completed' => 1, 'cancelled' => 0],
            'today' => 1,
            'overdue' => 1,
        ]]);

        Sanctum::actingAs($this->adminB);
        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 0, 'scheduled' => 2, 'in_progress' => 1, 'completed' => 0, 'cancelled' => 1],
            'today' => 1,
            'overdue' => 0,
        ]]);

        // Admin tidak boleh membuka scope lewat filter manager.
        $this->getJson('/api/v1/surveys/counts?account='.$this->accountA)
            ->assertOk()->assertJsonPath('data.by_state.scheduled', 2)
            ->assertJsonPath('data.by_state.requested', 0);
    }

    public function test_search_and_list_filters_apply_but_state_is_ignored(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/surveys/counts?search=Budi')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 0, 'scheduled' => 2, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0],
            'today' => 2,
            'overdue' => 0,
        ]]);

        // `state` hanya untuk daftar; counts tetap menghitung semua state.
        $this->getJson('/api/v1/surveys/counts?state=completed')->assertOk()
            ->assertJsonPath('data.by_state.scheduled', 4)
            ->assertJsonPath('data.by_state.completed', 1);

        $this->getJson('/api/v1/surveys/counts?surveyor_id='.$this->surveyorTwo->id)->assertOk()
            ->assertJsonPath('data.by_state.scheduled', 1)
            ->assertJsonPath('data.by_state.in_progress', 1)
            ->assertJsonPath('data.by_state.completed', 1)
            ->assertJsonPath('data.today', 1);

        $this->getJson('/api/v1/surveys/counts?account='.$this->accountA)->assertOk()
            ->assertJsonPath('data.by_state.requested', 1)
            ->assertJsonPath('data.by_state.scheduled', 2)
            ->assertJsonPath('data.by_state.completed', 1);

        $this->getJson('/api/v1/surveys/counts?start_date=2026-10-31&end_date=2026-10-31')->assertOk()
            ->assertJsonPath('data.by_state.scheduled', 2)
            ->assertJsonPath('data.by_state.in_progress', 1)
            ->assertJsonPath('data.today', 2)
            ->assertJsonPath('data.overdue', 0);

        Sanctum::actingAs($this->surveyorOne);
        $this->getJson('/api/v1/surveys/counts?search=Budi')->assertOk()
            ->assertJsonPath('data.by_state.scheduled', 1)
            ->assertJsonPath('data.today', 1);
    }

    public function test_index_filters_still_behave_after_extraction(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/surveys?search=Budi')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/surveys?state=scheduled')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.0.id', $this->ids['overdue']);
        $this->getJson('/api/v1/surveys?state=scheduled&search=Budi')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/surveys?surveyor_id='.$this->surveyorTwo->id)->assertOk()->assertJsonPath('meta.total', 3);

        Sanctum::actingAs($this->surveyorOne);
        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_counts_route_is_not_captured_by_survey_binding(): void
    {
        $this->assertSame('/api/v1/surveys/counts', route('api.surveys.counts', absolute: false));

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertJsonStructure([
            'data' => ['by_state' => Survey::STATES, 'today', 'overdue'],
        ]);

        $route = app('router')->getRoutes()->match(Request::create('/api/v1/surveys/counts', 'GET'));
        $this->assertSame('api.surveys.counts', $route->getName());
        $show = app('router')->getRoutes()->match(Request::create('/api/v1/surveys/'.$this->ids['today'], 'GET'));
        $this->assertSame('api.surveys.show', $show->getName());
    }

    public function test_empty_scope_returns_all_states_as_zero(): void
    {
        $lonely = $this->user(UserRole::Surveyor);
        Sanctum::actingAs($lonely);

        $this->getJson('/api/v1/surveys/counts')->assertOk()->assertExactJson(['data' => [
            'by_state' => ['requested' => 0, 'scheduled' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0],
            'today' => 0,
            'overdue' => 0,
        ]]);
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/surveys/counts')->assertUnauthorized();
    }

    private function seedFixtures(): void
    {
        $this->accountA = DB::table('accounts')->insertGetId(['name' => 'Akun Alpha', 'account_group' => 'PC']);
        $this->accountB = DB::table('accounts')->insertGetId(['name' => 'Akun Beta', 'account_group' => 'PC']);

        $this->superAdmin = $this->user(UserRole::SuperAdmin);
        $this->manager = $this->user(UserRole::ManagerSurveyor);
        $this->surveyorOne = $this->user(UserRole::Surveyor);
        $this->surveyorTwo = $this->user(UserRole::Surveyor);
        $this->adminA = $this->user(UserRole::Admin, $this->accountA);
        $this->adminB = $this->user(UserRole::Admin, $this->accountB);

        $s1 = $this->surveyorOne->id;
        $s2 = $this->surveyorTwo->id;
        $a = $this->accountA;
        $b = $this->accountB;
        $adminA = $this->adminA->id;
        $adminB = $this->adminB->id;

        $this->ids['today'] = $this->survey('today', $a, $adminA, $s1, Survey::STATE_SCHEDULED, '2026-10-31 09:00:00', 'Budi Santoso');
        $this->ids['overdue'] = $this->survey('overdue', $a, $adminA, $s1, Survey::STATE_SCHEDULED, '2026-10-30 14:00:00', 'Citra');
        $this->survey('future', $b, $adminB, $s1, Survey::STATE_SCHEDULED, '2026-11-01 10:00:00', 'Dedi');
        $this->survey('requested', $a, $adminA, null, Survey::STATE_REQUESTED, null, 'Eka');
        $this->survey('early', $b, $adminB, $s2, Survey::STATE_SCHEDULED, '2026-10-31 00:30:00', 'Budi Hartono');
        // In-progress lewat jadwal tidak dihitung overdue (hanya state scheduled).
        $this->survey('progress', $b, $adminB, $s2, Survey::STATE_IN_PROGRESS, '2026-10-31 08:00:00', 'Fajar');
        $this->survey('completed', $a, $adminA, $s2, Survey::STATE_COMPLETED, '2026-10-29 10:00:00', 'Gina');
        $this->survey('cancelled', $b, $adminB, null, Survey::STATE_CANCELLED, null, 'Hadi');
        $deleted = $this->survey('deleted', $a, $adminA, $s1, Survey::STATE_SCHEDULED, '2026-10-31 11:00:00', 'Budi Terhapus');
        DB::table('surveys')->where('id', $deleted)->update(['deleted_at' => now()]);
    }

    private function survey(
        string $key,
        int $accountId,
        int $requestedBy,
        ?int $surveyorId,
        string $state,
        ?string $scheduledAt,
        string $clientName,
    ): int {
        static $sequence = 0;
        $sequence++;

        $consultationId = DB::table('consultations')->insertGetId([
            'consultation_id' => 'CNS-'.$key,
            'client_name' => $clientName,
            'account_id' => $accountId,
            'address' => 'Jl. '.$key,
        ]);

        return DB::table('surveys')->insertGetId([
            'consultation_id' => $consultationId,
            'account_id' => $accountId,
            'state' => $state,
            'requested_by' => $requestedBy,
            'requested_at' => now()->subDays(5)->addMinutes($sequence),
            'requested_item' => 'Item '.$key,
            'surveyor_id' => $surveyorId,
            'scheduled_at' => $scheduledAt,
            'created_at' => now(),
            'updated_at' => now()->addMinutes($sequence),
        ]);
    }

    private function user(UserRole $role, ?int $accountId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role->value.' '.uniqid(),
            'email' => uniqid('', true).'@example.test',
            'password' => 'hash', 'role' => $role->value, 'account_id' => $accountId,
        ]);

        if ($accountId !== null) {
            DB::table('account_user')->insert(['user_id' => $id, 'account_id' => $accountId]);
        }

        return User::query()->findOrFail($id);
    }

    private function createFixtureSchema(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('account_group', 10)->nullable();
            $table->unsignedBigInteger('account_group_id')->nullable();
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
        Schema::create('needs_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('consultation_needs_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id');
            $table->foreignId('needs_category_id');
            $table->timestamps();
        });
        Schema::create('survey_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->nullable();
            $table->string('css_class')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('consultation_id')->nullable();
            $table->string('client_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('emergency_phone_normalized')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('account_id')->nullable();
            $table->foreignId('needs_category_id')->nullable();
            $table->text('product_details')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id');
            $table->foreignId('account_id');
            $table->string('state');
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->date('requested_date')->nullable();
            $table->string('requested_item')->nullable();
            $table->foreignId('surveyor_id')->nullable();
            $table->foreignId('assigned_by')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_finish_at')->nullable();
            $table->foreignId('result_status_id')->nullable();
            $table->string('active_key')->nullable();
            $table->string('schedule_slot_key')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
