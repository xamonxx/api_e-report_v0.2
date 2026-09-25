<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\User;
use App\Services\WebPushService;
use App\Support\AccountGroup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak akses GACONG di luar Team F (B2): pinjaman surveyor lintas tim
 * selain Team F (yang sudah diizinkan bebas, lihat SurveyController::
 * BORROWABLE_TEAM) wajib disetujui Super Admin per kasus dan tersimpan
 * terstruktur (SurveyLoanApproval) - bukan dibuka begitu saja untuk semua
 * tim, dan bukan cuma teks "[GACONG]" tanpa otorisasi nyata.
 *
 * Fixture sendiri (pola sama seperti SurveyTeamIsolationTest): RefreshDatabase
 * tidak dipakai karena migration operasional 2026_09_23_000002 butuh ID akun
 * produksi. Team F sengaja tanpa surveyor sendiri, sama seperti kondisi nyata.
 */
class SurveyLoanApprovalTest extends TestCase
{
    private array $teams = [];

    private User $superAdmin;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'survey_loan_isolation',
                'database.connections' => [
                    'survey_loan_isolation' => [
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
        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(8, 0));
        $this->createFixtureSchema();
        $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->andReturnNull();

        // A dan C punya surveyor sendiri. F sengaja tanpa surveyor (kondisi
        // nyata Team F saat ini) buat memastikan fix candidate-list-kosong
        // ikut teruji di sini, bukan cuma di SurveyTeamIsolationTest.
        foreach (['A', 'C', 'F'] as $team) {
            $account = DB::table('accounts')->insertGetId(['name' => 'Account '.$team, 'account_group' => $team]);
            $this->teams[$team] = [
                'account' => $account,
                'manager' => $this->user(UserRole::ManagerSurveyor, $team),
                'surveyor' => $team === 'F' ? null : $this->user(UserRole::Surveyor, $team),
            ];
        }
        $this->superAdmin = $this->user(UserRole::SuperAdmin);
    }

    /** Manager tidak boleh membuat pinjaman baru di luar Team F - hanya Super Admin. */
    public function test_manager_cannot_create_cross_team_loan_outside_team_f(): void
    {
        $survey = $this->survey('C', ['state' => 'requested']);
        Sanctum::actingAs($this->teams['C']['manager']);

        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'loan_reason' => 'Coba pinjam sendiri tanpa Super Admin',
        ])->assertStatus(422);

        $this->assertDatabaseCount('survey_loan_approvals', 0);
        $this->assertSame('requested', $survey->fresh()->state);
    }

    /** Super Admin bisa membuat pinjaman lintas tim di luar Team F, tersimpan terstruktur, dan tercatat GACONG. */
    public function test_super_admin_can_approve_cross_team_loan_outside_team_f(): void
    {
        $survey = $this->survey('C', ['state' => 'requested']);
        Sanctum::actingAs($this->superAdmin);

        $response = $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'loan_reason' => 'Team C kekurangan surveyor minggu ini',
        ])->assertOk();

        $response->assertJsonPath('data.state', 'scheduled');
        $this->assertDatabaseCount('survey_loan_approvals', 1);
        $this->assertDatabaseHas('survey_loan_approvals', [
            'survey_id' => $survey->id,
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'borrower_team' => 'C',
            'lender_team' => 'A',
            'approved_by' => $this->superAdmin->id,
            'reason' => 'Team C kekurangan surveyor minggu ini',
            'revoked_at' => null,
        ]);
        $this->assertStringContainsString('[GACONG]', $survey->fresh()->location_notes ?? '');
    }

    /** Super Admin tanpa alasan ditolak - alasan wajib buat pinjaman baru. */
    public function test_super_admin_loan_requires_a_reason(): void
    {
        $survey = $this->survey('C', ['state' => 'requested']);
        Sanctum::actingAs($this->superAdmin);

        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('survey_loan_approvals', 0);
    }

    /** Setelah disetujui, surveyor pinjaman bisa melihat & menjalankan survey tim peminjam. */
    public function test_loaned_surveyor_can_see_and_act_on_the_borrowed_survey(): void
    {
        $survey = $this->survey('C', ['state' => 'requested']);
        Sanctum::actingAs($this->superAdmin);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'loan_reason' => 'Pinjaman disetujui untuk uji akses',
        ])->assertOk();

        Sanctum::actingAs($this->teams['A']['surveyor']);
        $this->getJson('/api/v1/surveys/'.$survey->id)->assertOk();
        $this->getJson('/api/v1/surveys')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $survey->id);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/start')->assertOk();
    }

    /** Surveyor Team A tanpa persetujuan tetap TIDAK bisa melihat survey Team C - loan bukan izin permanen lintas tim. */
    public function test_surveyor_without_an_approval_still_cannot_see_other_team_survey(): void
    {
        $survey = $this->survey('C', ['state' => 'scheduled', 'surveyor_id' => $this->teams['C']['surveyor']->id]);
        Sanctum::actingAs($this->teams['A']['surveyor']);

        $this->getJson('/api/v1/surveys/'.$survey->id)->assertForbidden();
    }

    /** Team F: manager melihat SEMUA surveyor sebagai kandidat (bug lama: daftar kosong karena Team F belum punya surveyor sendiri). */
    public function test_team_f_manager_sees_all_surveyors_as_candidates(): void
    {
        Sanctum::actingAs($this->teams['F']['manager']);

        $response = $this->getJson('/api/v1/master-data/surveyors')->assertOk();
        $names = collect($response->json('data'))->pluck('id');
        $this->assertTrue($names->contains($this->teams['A']['surveyor']->id));
        $this->assertTrue($names->contains($this->teams['C']['surveyor']->id));
    }

    /** Team F: assign tanpa persetujuan tambahan tetap jalan (aturan lama dipertahankan, bukan diperluas jadi butuh approval juga). */
    public function test_team_f_borrowing_still_needs_no_extra_approval(): void
    {
        $survey = $this->survey('F', ['state' => 'requested']);
        Sanctum::actingAs($this->teams['F']['manager']);

        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->teams['A']['surveyor']->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertOk()->assertJsonPath('data.state', 'scheduled');

        $this->assertDatabaseCount('survey_loan_approvals', 0);
        $this->assertStringContainsString('[GACONG]', $survey->fresh()->location_notes ?? '');
    }

    private function user(UserRole $role, ?string $team = null, ?int $account = null): User
    {
        $number = DB::table('users')->count() + 1;
        $id = DB::table('users')->insertGetId([
            'name' => $role->value.' '.$team.' '.$number,
            'email' => 'loan-fixture-'.$number.'@example.test', 'password' => 'unused',
            'role' => $role->value, 'survey_team' => $team, 'account_id' => $account,
        ]);

        return User::findOrFail($id);
    }

    private function survey(string $team, array $attributes = []): Survey
    {
        $members = $this->teams[$team];
        $consultation = DB::table('consultations')->insertGetId([
            'consultation_id' => 'LOAN-FIXTURE-'.(DB::table('consultations')->count() + 1),
            'client_name' => 'Client '.$team, 'account_id' => $members['account'],
            'account_group' => $team, 'created_by' => $this->superAdmin->id ?? null,
        ]);
        $id = DB::table('surveys')->insertGetId(array_merge([
            'consultation_id' => $consultation, 'active_key' => $consultation,
            'account_id' => $members['account'], 'requested_by' => $this->superAdmin->id ?? null,
            'state' => 'requested', 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));

        return Survey::findOrFail($id);
    }

    private function createFixtureSchema(): void
    {
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
