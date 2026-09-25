<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Events\SurveyRealtimeUpdated;
use App\Models\Survey;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SurveyTeamIsolationTest extends TestCase
{
    private array $teams = [];

    private User $superAdmin;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        // Replace even cached/live configuration before providers can open a connection.
        // RefreshDatabase is intentionally absent: the operational 2026_09_23_000002
        // migration requires production account IDs and cannot build a fresh database.
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'survey_isolation',
                'database.connections' => [
                    'survey_isolation' => [
                        'driver' => 'sqlite', 'database' => ':memory:',
                        'prefix' => '', 'foreign_key_constraints' => true,
                    ],
                ],
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'broadcasting.default' => 'null',
                'mail.default' => 'array',
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
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(8, 0));
        $this->createFixtureSchema();
        $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->andReturnNull();

        foreach (['A', 'B', 'C', 'D', 'E'] as $team) {
            $account = DB::table('accounts')->insertGetId(['name' => 'Account '.$team, 'account_group' => $team]);
            $this->teams[$team] = [
                'account' => $account,
                'admin' => $this->user(UserRole::Admin, null, $account),
                'manager' => $this->user(UserRole::ManagerSurveyor, $team),
                'surveyor' => $this->user(UserRole::Surveyor, $team),
            ];
        }
        $this->superAdmin = $this->user(UserRole::SuperAdmin);
    }

    public function test_manager_list_detail_and_history_use_current_account_team(): void
    {
        $own = $this->survey('A');
        $other = $this->survey('B');
        Sanctum::actingAs($this->teams['A']['manager']);

        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/surveys?account='.$other->account_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/surveys?search=Client%20B')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/surveys/'.$own->id)->assertOk();
        $this->getJson('/api/v1/surveys/'.$other->id)->assertForbidden();
        $this->getJson('/api/v1/surveys/'.$other->id.'/history')->assertForbidden();

        // Historical consultation snapshots must not grant access after an account moves.
        DB::table('accounts')->where('id', $own->account_id)->update(['account_group' => 'B']);
        $this->assertDatabaseHas('consultations', ['id' => $own->consultation_id, 'account_group' => 'A']);
        $this->getJson('/api/v1/surveys/'.$own->id)->assertForbidden();
        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->teams['B']['manager']);
        $this->getJson('/api/v1/surveys/'.$own->id)->assertOk();
    }

    public static function managerActions(): array
    {
        return [
            'assign' => ['assign', 'requested'],
            'unassign' => ['unassign', 'scheduled'],
            'reschedule assignment' => ['reschedule-assignment', 'scheduled'],
            'start' => ['start', 'scheduled'],
            'result' => ['result', 'in_progress'],
            'cancel' => ['cancel', 'scheduled'],
        ];
    }

    #[DataProvider('managerActions')]
    public function test_manager_cannot_mutate_another_team(string $action, string $state): void
    {
        $survey = $this->survey('B', ['state' => $state]);
        $before = $survey->getAttributes();
        Sanctum::actingAs($this->teams['A']['manager']);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/'.$action, [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => '2026-09-25 10:00:00',
            'result_status_id' => 1, 'result_notes' => 'Forbidden result',
            'reason' => 'Forbidden cancellation',
        ])->assertForbidden();
        $this->assertSame($before, $survey->fresh()->getAttributes());
        foreach (['survey_activity_logs', 'survey_status_histories', 'survey_reschedules', 'survey_notifications', 'audit_logs', 'survey_loan_approvals', 'survey_reminder_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_assignment_and_reassignment_reject_cross_team_and_inactive_surveyors(): void
    {
        Sanctum::actingAs($this->teams['A']['manager']);
        $archived = $this->user(UserRole::Surveyor, 'A');
        DB::table('users')->where('id', $archived->id)->update(['deleted_at' => now()]);
        $invalid = [$this->teams['B']['surveyor'], $this->user(UserRole::Surveyor), $archived, $this->teams['A']['manager']];

        foreach (['assign' => 'requested', 'reschedule-assignment' => 'scheduled'] as $action => $state) {
            $survey = $this->survey('A', ['state' => $state]);
            $before = $survey->getAttributes();
            foreach ($invalid as $surveyor) {
                $this->patchJson('/api/v1/surveys/'.$survey->id.'/'.$action, [
                    'surveyor_id' => $surveyor->id, 'scheduled_at' => '2026-09-25 11:00:00',
                ])->assertUnprocessable();
                $this->assertSame($before, $survey->fresh()->getAttributes());
            }
        }
        $this->assertDatabaseCount('survey_notifications', 0);
        $this->assertDatabaseCount('survey_activity_logs', 0);
    }

    public function test_manager_can_assign_within_team_and_superadmin_can_manage_another_team(): void
    {
        foreach (['A' => $this->teams['A']['manager'], 'B' => $this->superAdmin] as $team => $actor) {
            $survey = $this->survey($team, ['state' => 'requested', 'surveyor_id' => null]);
            Sanctum::actingAs($actor);
            $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
                'surveyor_id' => $this->teams[$team]['surveyor']->id,
                'scheduled_at' => '2026-09-25 11:00:00',
            ])->assertOk()->assertJsonPath('data.state', 'scheduled');
            $this->assertDatabaseHas('surveys', ['id' => $survey->id, 'assigned_by' => $actor->id]);
            $this->assertDatabaseHas('survey_status_histories', ['survey_id' => $survey->id, 'to_state' => 'scheduled']);
        }
        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(2, 'data');
        foreach (['view', 'assign', 'reschedule', 'updateMaps', 'rescheduleAssignment', 'start', 'submitResult', 'cancel'] as $ability) {
            $this->assertTrue(Gate::forUser($this->superAdmin)->allows($ability, $survey), $ability);
        }
    }

    public function test_surveyor_needs_both_current_team_and_ownership(): void
    {
        $surveyor = $this->teams['A']['surveyor'];
        $own = $this->survey('A');
        $colleague = $this->survey('A', ['surveyor_id' => $this->user(UserRole::Surveyor, 'A')->id]);
        $staleAssignment = $this->survey('B', ['surveyor_id' => $surveyor->id]);
        Sanctum::actingAs($surveyor);
        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/surveys/'.$own->id)->assertOk();
        foreach ([$colleague, $staleAssignment] as $hidden) {
            $this->getJson('/api/v1/surveys/'.$hidden->id)->assertForbidden();
            $this->patchJson('/api/v1/surveys/'.$hidden->id.'/start')->assertForbidden();
        }
    }

    public function test_masterdata_availability_and_recap_are_scoped_even_with_hostile_filters(): void
    {
        $own = $this->survey('A');
        $other = $this->survey('B');
        // A stale assignment in another team must not increase A's schedule count.
        $this->survey('B', ['surveyor_id' => $this->teams['A']['surveyor']->id]);
        $this->user(UserRole::Surveyor);
        $archived = $this->user(UserRole::Surveyor, 'A');
        DB::table('users')->where('id', $archived->id)->update(['deleted_at' => now()]);
        Sanctum::actingAs($this->teams['A']['manager']);

        $this->getJson('/api/v1/master-data/surveyors?survey_team=B')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->teams['A']['surveyor']->id);
        $this->getJson('/api/v1/surveys/availability?date=2026-09-25')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->teams['A']['surveyor']->id)->assertJsonPath('data.0.schedule_count', 1);
        $this->getJson('/api/v1/surveys/availability?date=2026-09-25&exclude_survey_id='.$own->id)
            ->assertOk()->assertJsonPath('data.0.schedule_count', 0);
        $this->getJson('/api/v1/surveys/availability?date=2026-09-25&exclude_survey_id='.$other->id)->assertNotFound();
        $this->getJson('/api/v1/surveys/recap?week_date=2026-09-25')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.summary.0.surveyorId', $this->teams['A']['surveyor']->id)
            ->assertJsonMissing(['clientName' => 'Client B']);
        foreach (['account_group=B', 'account='.$other->account_id, 'surveyor='.$this->teams['B']['surveyor']->id] as $filter) {
            $this->getJson('/api/v1/surveys/recap?week_date=2026-09-25&'.$filter)->assertOk()->assertJsonPath('data.total', 0);
        }
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/master-data/surveyors')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/surveys/availability?date=2026-09-25')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/surveys/recap?week_date=2026-09-25')->assertOk()->assertJsonPath('data.total', 3);
    }

    public function test_missing_or_invalid_team_denies_survey_access(): void
    {
        $survey = $this->survey('A');
        foreach ([null, '', 'Z', 'NPP 1'] as $team) {
            foreach ([UserRole::ManagerSurveyor, UserRole::Surveyor] as $role) {
                $actor = $this->user($role, $team);
                Sanctum::actingAs($actor);
                $this->assertFalse($actor->hasSurveyTeam());
                $this->assertSame(0, Survey::query()->visibleTo($actor)->count());
                $this->getJson('/api/v1/surveys')->assertForbidden();
                $this->getJson('/api/v1/surveys/'.$survey->id)->assertForbidden();
                $this->getJson('/api/v1/surveys/recap')->assertForbidden();
                $this->getJson('/api/v1/surveys/availability?date=2026-09-25')->assertForbidden();
                $this->patchJson('/api/v1/surveys/'.$survey->id.'/cancel', ['reason' => 'No team'])->assertForbidden();
                if ($role === UserRole::ManagerSurveyor) {
                    $this->getJson('/api/v1/master-data/surveyors')->assertOk()->assertJsonCount(0, 'data');
                }
            }
        }
    }

    public function test_duplicate_manager_and_missing_survey_team_are_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $payload = ['name' => 'Duplicate Manager', 'email' => 'duplicate@test.local',
            'password' => 'Testing123!', 'password_confirmation' => 'Testing123!',
            'role' => 'manager_surveyor', 'survey_team' => 'A'];
        $this->postJson('/api/v1/master-data/users', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('survey_team');
        unset($payload['survey_team']);
        $this->postJson('/api/v1/master-data/users', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('survey_team');
        $this->assertDatabaseMissing('users', ['email' => 'duplicate@test.local']);
    }

    public function test_account_group_filter_and_edit_use_team_not_legacy_description(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $id = $this->teams['A']['account'];
        DB::table('accounts')->where('id', $id)->update(['description' => 'NPP 1']);
        $this->getJson('/api/v1/accounts/categories')->assertOk()
            ->assertExactJson(['data' => ['A', 'B', 'C', 'D', 'E']]);
        $this->getJson('/api/v1/accounts?category=A')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.account_group', 'A');
        $this->getJson('/api/v1/accounts?category=NPP1')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/accounts/'.$id, ['name' => 'Account A', 'description' => 'NPP 1', 'account_group' => 'B'])
            ->assertOk()->assertJsonPath('data.account_group', 'B')->assertJsonPath('data.description', 'NPP 1');
        $this->putJson('/api/v1/accounts/'.$id, ['name' => 'Account A', 'account_group' => 'INVALID'])
            ->assertUnprocessable()->assertJsonValidationErrors('account_group');
        $this->postJson('/api/v1/accounts', ['name' => 'New Team Account', 'account_group' => 'E'])
            ->assertCreated()->assertJsonPath('data.account_group', 'E');
    }

    public function test_retired_users_cannot_be_resolved_by_login_or_existing_session(): void
    {
        $user = $this->teams['A']['surveyor'];
        $provider = app('auth')->createUserProvider(config('auth.guards.web.provider'));
        $this->assertNotNull($provider->retrieveById($user->id));
        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);
        $this->assertNull($provider->retrieveById($user->id));
        $this->assertNull($provider->retrieveByCredentials(['email' => $user->email]));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_roster_reset_preserves_progress_and_can_be_assigned_again(): void
    {
        $survey = $this->survey('A', ['state' => 'in_progress',
            'surveyor_id' => $this->teams['B']['surveyor']->id,
            'actual_start_at' => '2026-09-25 09:10:00', 'result_notes' => 'Existing progress']);
        $command = new \App\Console\Commands\SyncSurveyTeams();
        $reset = new \ReflectionMethod($command, 'resetSurvey');
        $reset->invoke($command, [
            'survey' => DB::table('surveys')->find($survey->id),
            'consultation' => DB::table('consultations')->find($survey->consultation_id),
            'reset_status' => false,
        ], null);
        $after = $survey->fresh();
        $this->assertSame('requested', $after->state);
        $this->assertNull($after->surveyor_id);
        $this->assertNull($after->actual_start_at);
        $this->assertNull($after->scheduled_at);
        $snapshot = json_decode(DB::table('survey_activity_logs')->where('survey_id', $survey->id)->value('old_values'), true);
        $this->assertSame('Existing progress', $snapshot['survey']['result_notes']);
        $this->assertSame('2026-09-25 09:10:00', $snapshot['survey']['actual_start_at']);
        $this->assertSame($survey->surveyor_id, $snapshot['survey']['surveyor_id']);
        $this->assertSame($survey->consultation_id, (int) $after->active_key);
        $this->assertDatabaseHas('survey_status_histories', ['survey_id' => $survey->id, 'from_state' => 'in_progress', 'to_state' => 'requested']);
        Sanctum::actingAs($this->teams['A']['manager']);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id, 'scheduled_at' => '2026-09-25 11:00:00',
        ])->assertOk()->assertJsonPath('data.state', 'scheduled');
    }

    public function test_each_valid_team_sees_only_its_own_surveys(): void
    {
        foreach (array_keys($this->teams) as $team) {
            $this->survey($team);
        }
        foreach ($this->teams as $members) {
            Sanctum::actingAs($members['manager']);
            $this->assertTrue($members['manager']->hasSurveyTeam());
            $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.account_id', $members['account']);
        }
    }

    public function test_recipients_and_persisted_notifications_never_cross_team_boundaries(): void
    {
        $survey = $this->survey('A');
        $noTeam = $this->user(UserRole::ManagerSurveyor);
        $archived = $this->user(UserRole::ManagerSurveyor, 'A');
        DB::table('users')->where('id', $archived->id)->update(['deleted_at' => now()]);
        $extras = [$this->teams['B']['manager']->id, $this->teams['B']['surveyor']->id, $noTeam->id, $archived->id];
        $managers = [$this->teams['A']['manager']->id, $this->superAdmin->id];
        $participants = [$this->teams['A']['admin']->id, $this->teams['A']['surveyor']->id];
        foreach (['request_created', 'started', 'rescheduled_by_admin', 'unassigned', 'scheduled', 'rescheduled_by_manager', 'completed', 'cancelled', 'maps_updated'] as $action) {
            $expected = match ($action) {
                'scheduled', 'rescheduled_by_manager' => [$this->teams['A']['surveyor']->id],
                'completed', 'cancelled', 'maps_updated' => array_merge($managers, $participants),
                default => $managers,
            };
            $this->assertEqualsCanonicalizing($expected, SurveyRealtimeUpdated::recipientsFor($survey, $action, $extras), $action);
            new SurveyRealtimeUpdated($survey, $action, 'Synthetic notification', $extras);
            $this->assertEqualsCanonicalizing($expected, DB::table('survey_notifications')->where('action', $action)->pluck('user_id')->all(), $action);
        }
        DB::table('surveys')->where('id', $survey->id)->update([
            'surveyor_id' => $this->teams['B']['surveyor']->id,
            'assigned_by' => $this->teams['B']['manager']->id,
        ]);
        $this->assertEqualsCanonicalizing(array_merge($managers, [$this->teams['A']['admin']->id]),
            SurveyRealtimeUpdated::recipientsFor($survey->fresh(), 'completed', $extras));
    }

    public function test_broadcast_channels_authorize_only_the_intended_team(): void
    {
        // Exercise the actual registered callbacks without contacting a websocket server.
        $channels = Broadcast::getChannels();
        $manager = $this->teams['A']['manager'];
        $noTeam = $this->user(UserRole::ManagerSurveyor);
        $this->assertFalse($channels['survey.managers']($manager));
        $this->assertTrue($channels['survey.managers']($this->superAdmin));
        $this->assertTrue($channels['survey.managers.{team}']($manager, 'A'));
        $this->assertFalse($channels['survey.managers.{team}']($manager, 'B'));
        $this->assertFalse($channels['survey.managers.{team}']($noTeam, 'A'));
        $this->assertFalse($channels['survey.managers.{team}']($this->superAdmin, 'Z'));
        $personal = $channels['survey.surveyor.{surveyorId}'];
        $this->assertTrue($personal($manager, $this->teams['A']['surveyor']->id));
        $this->assertFalse($personal($manager, $this->teams['B']['surveyor']->id));
        $this->assertFalse($personal($noTeam, $this->teams['A']['surveyor']->id));
        $this->assertTrue($personal($this->teams['A']['surveyor'], $this->teams['A']['surveyor']->id));
        $this->assertFalse($personal($this->teams['A']['surveyor'], $this->teams['B']['surveyor']->id));
        $this->assertTrue($personal($this->superAdmin, $this->teams['B']['surveyor']->id));

        $survey = $this->survey('A');
        $survey->load('surveyor:id,name');
        $event = new SurveyRealtimeUpdated($survey, 'scheduled', 'Synthetic schedule');
        $names = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertEqualsCanonicalizing([
            'private-survey.managers', 'private-survey.managers.A',
            'private-survey.account.'.$survey->account_id,
            'private-survey.surveyor.'.$this->teams['A']['surveyor']->id,
        ], $names);
        DB::table('surveys')->where('id', $survey->id)->update(['surveyor_id' => $this->teams['B']['surveyor']->id]);
        $event = new SurveyRealtimeUpdated($survey->fresh(), 'scheduled', 'Stale assignment');
        $this->assertNotContains('private-survey.surveyor.'.$this->teams['B']['surveyor']->id,
            array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
    }

    public function test_archived_actor_names_remain_in_detail_history_and_recap(): void
    {
        $survey = $this->survey('A');
        $manager = $this->teams['A']['manager'];
        DB::table('survey_status_histories')->insert(['survey_id' => $survey->id, 'to_state' => 'scheduled', 'changed_by' => $manager->id]);
        DB::table('survey_reschedules')->insert(['survey_id' => $survey->id, 'source' => 'manager', 'field' => 'scheduled', 'changed_by' => $manager->id]);
        DB::table('survey_activity_logs')->insert(['survey_id' => $survey->id, 'action' => 'scheduled', 'user_id' => $manager->id]);
        DB::table('users')->whereIn('id', [$manager->id, $survey->surveyor_id, $survey->requested_by])->update(['deleted_at' => now()]);
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/surveys/'.$survey->id)->assertOk()
            ->assertJsonPath('data.surveyor.name', $this->teams['A']['surveyor']->name)
            ->assertJsonPath('data.requester.name', $this->teams['A']['admin']->name)
            ->assertJsonPath('data.assigner.name', $manager->name)
            ->assertJsonPath('data.histories.0.changed_by.name', $manager->name)
            ->assertJsonPath('data.reschedules.0.changed_by.name', $manager->name)
            ->assertJsonPath('data.activity_logs.0.user.name', $manager->name);
        $this->getJson('/api/v1/surveys/'.$survey->id.'/history')->assertOk()->assertJsonPath('data.0.user.name', $manager->name);
        $this->getJson('/api/v1/surveys/recap?week_date=2026-09-25')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.summary.0.surveyorName', $this->teams['A']['surveyor']->name);
    }

    private function user(UserRole $role, ?string $team = null, ?int $account = null): User
    {
        $number = DB::table('users')->count() + 1;
        $id = DB::table('users')->insertGetId([
            'name' => $role->value.' '.$team.' '.$number,
            'email' => 'fixture-'.$number.'@example.test', 'password' => 'unused',
            'role' => $role->value, 'survey_team' => $team, 'account_id' => $account,
        ]);

        return User::findOrFail($id);
    }

    private function survey(string $team, array $attributes = []): Survey
    {
        $members = $this->teams[$team];
        $consultation = DB::table('consultations')->insertGetId([
            'consultation_id' => 'FIXTURE-'.(DB::table('consultations')->count() + 1),
            'client_name' => 'Client '.$team, 'account_id' => $members['account'],
            'account_group' => $team, 'created_by' => $members['admin']->id,
        ]);
        $id = DB::table('surveys')->insertGetId(array_merge([
            'consultation_id' => $consultation, 'active_key' => $consultation,
            'account_id' => $members['account'], 'requested_by' => $members['admin']->id,
            'assigned_by' => $members['manager']->id, 'surveyor_id' => $members['surveyor']->id,
            'state' => 'scheduled', 'scheduled_at' => '2026-09-25 09:00:00',
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));

        return Survey::findOrFail($id);
    }

    private function createFixtureSchema(): void
    {
        // Minimal final schema, including FK and active-survey uniqueness constraints.
        // Raw fixture inserts avoid model observers manufacturing unrelated audit history.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('account_group')->nullable();
            $table->string('description')->nullable();
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique();
            $table->string('password'); $table->string('role'); $table->string('survey_team')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable(); $table->rememberToken();
            $table->timestamps(); $table->softDeletes();
        });
        foreach (['needs_categories', 'status_categories', 'survey_statuses'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id(); $table->string('name'); $table->string('color')->nullable();
                $table->string('css_class')->nullable(); $table->integer('sort_order')->default(0);
                $table->timestamps(); $table->softDeletes();
            });
        }
        DB::table('survey_statuses')->insert(['id' => 1, 'name' => 'Completed']);
        Schema::create('consultations', function (Blueprint $table) {
            $table->id(); $table->string('consultation_id')->unique(); $table->string('client_name');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('needs_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_category_id')->nullable()->constrained()->nullOnDelete();
            foreach (['account_group', 'phone', 'emergency_phone', 'phone_normalized', 'emergency_phone_normalized', 'province', 'city', 'district', 'address', 'product_details'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('consultation_needs_category', function (Blueprint $table) {
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('needs_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['consultation_id', 'needs_category_id']); $table->timestamps();
        });
        Schema::create('surveys', function (Blueprint $table) {
            $table->id(); $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('active_key')->nullable()->unique();
            foreach (['requested_by', 'assigned_by', 'surveyor_id'] as $column) {
                $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
            }
            $table->foreignId('result_status_id')->nullable()->constrained('survey_statuses')->nullOnDelete();
            $table->string('state')->default('requested'); $table->date('requested_date')->nullable();
            $table->time('requested_time')->nullable();
            foreach (['requested_at', 'assigned_at', 'scheduled_at', 'actual_start_at', 'actual_finish_at', 'completed_at', 'cancelled_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            foreach (['requested_item', 'location_notes', 'admin_notes', 'manager_notes', 'google_maps_url', 'result_notes', 'cancellation_reason', 'location_condition', 'customer_notes', 'obstacles', 'recommendations', 'additional_notes'] as $column) {
                $table->text($column)->nullable();
            }
            $table->unsignedInteger('schedule_revision')->default(0);
            $table->timestamps(); $table->softDeletes();
        });
        Schema::create('survey_reminder_settings', function (Blueprint $table) {
            $table->id(); $table->string('key', 40)->unique()->default('default');
            $table->boolean('enabled')->default(false); $table->unsignedInteger('lead_minutes')->default(300);
            $table->text('message_template')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('survey_reminder_deliveries', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('schedule_revision');
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('scheduled_at_snapshot'); $table->unsignedInteger('lead_minutes');
            $table->timestamp('due_at'); $table->string('status', 20)->default('pending');
            $table->timestamp('claimed_at')->nullable(); $table->string('lease_token', 40)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->foreignId('notification_id')->nullable()->constrained('survey_notifications')->nullOnDelete();
            $table->timestamp('push_attempted_at')->nullable();
            $table->string('push_status', 25)->default('not_attempted');
            $table->string('last_error_code', 60)->nullable(); $table->timestamps();
            $table->unique(['survey_id', 'schedule_revision', 'recipient_id']);
        });
        Schema::create('survey_status_histories', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_state')->nullable(); $table->string('to_state'); $table->timestamps();
        });
        Schema::create('survey_reschedules', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source'); $table->string('field'); $table->string('changed_by_role')->nullable();
            $table->timestamp('old_at')->nullable(); $table->timestamp('new_at')->nullable();
            $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('survey_activity_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); $table->string('user_role')->nullable();
            $table->string('old_status')->nullable(); $table->string('new_status')->nullable();
            $table->json('old_values')->nullable(); $table->json('new_values')->nullable();
            $table->text('notes')->nullable(); $table->timestamps();
        });
        Schema::create('survey_notifications', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action'); $table->string('title'); $table->text('message');
            $table->timestamp('read_at')->nullable(); $table->timestamps();
        });
        Schema::create('survey_loan_approvals', function (Blueprint $table) {
            $table->id(); $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('surveyor_id')->constrained('users')->cascadeOnDelete();
            $table->string('borrower_team', 1); $table->string('lender_team', 1);
            $table->foreignId('approved_by')->constrained('users');
            $table->timestamp('approved_at'); $table->text('reason');
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable(); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->string('loggable_type'); $table->unsignedBigInteger('loggable_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            foreach (['action', 'user_name', 'description', 'ip_address', 'user_agent'] as $column) {
                $table->text($column)->nullable();
            }
            $table->json('old_values')->nullable(); $table->json('new_values')->nullable(); $table->timestamps();
        });
    }
}
