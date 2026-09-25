<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\SurveyReminderDelivery;
use App\Models\SurveyReminderSetting;
use App\Models\User;
use App\Services\SurveyReminderService;
use App\Services\WebPushService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B3: pengingat survey otomatis. Cakupan inti dari acceptance test rencana
 * (R01-R14) - due-time transition, idempotensi versi (reschedule/reassign
 * tidak mengirim ulang versi lama), pembatalan saat unassign/cancel, gagal
 * aman kalau push tidak tersedia, dan replan saat setting berubah. Fixture
 * sendiri (pola sama seperti test keamanan survey lain di folder ini).
 */
class SurveyReminderTest extends TestCase
{
    private array $team = [];

    private User $superAdmin;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'survey_reminder_isolation',
                'database.connections' => [
                    'survey_reminder_isolation' => [
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
        $this->createFixtureSchema();
        $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->byDefault()->andReturnNull();

        $account = DB::table('accounts')->insertGetId(['name' => 'Account A', 'account_group' => 'A']);
        $this->team = [
            'account' => $account,
            'manager' => $this->user(UserRole::ManagerSurveyor, 'A'),
            'surveyor' => $this->user(UserRole::Surveyor, 'A'),
        ];
        $this->superAdmin = $this->user(UserRole::SuperAdmin);

        $this->travelTo(now()->setDate(2026, 9, 26)->setTime(6, 0));
    }

    private function enableReminders(int $leadMinutes = 300): SurveyReminderSetting
    {
        return SurveyReminderSetting::query()->updateOrCreate(
            ['key' => SurveyReminderSetting::DEFAULT_KEY],
            ['enabled' => true, 'lead_minutes' => $leadMinutes]
        );
    }

    /** R01/R02-ish: assign membuat delivery pending dengan due_at yang benar (WIB), lalu terkirim tepat saat due. */
    public function test_assign_creates_pending_delivery_that_fires_when_due(): void
    {
        $this->enableReminders(300); // 5 jam
        $survey = $this->survey(['state' => 'requested']);
        Sanctum::actingAs($this->team['manager']);

        $scheduledAt = now()->addDay()->setTime(2, 0); // 02:00 WIB besok
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
        ])->assertOk();

        $delivery = SurveyReminderDelivery::query()->where('survey_id', $survey->id)->sole();
        $this->assertSame('pending', $delivery->status);
        // due 21:00 WIB HARI SEBELUMNYA (02:00 - 5 jam), sesuai contoh kontrak produk.
        $this->assertSame($scheduledAt->copy()->subMinutes(300)->toDateTimeString(), $delivery->due_at->toDateTimeString());

        $this->travelTo($delivery->due_at->copy()->subMinute());
        $this->artisan('surveys:dispatch-due-reminders')->assertSuccessful();
        $this->assertSame('pending', $delivery->fresh()->status);

        $this->travelTo($delivery->due_at);
        $this->artisan('surveys:dispatch-due-reminders')->assertSuccessful();
        $delivery->refresh();
        $this->assertSame('notified', $delivery->status);
        $this->assertNotNull($delivery->notification_id);
        $this->assertDatabaseHas('survey_notifications', [
            'id' => $delivery->notification_id,
            'user_id' => $this->team['surveyor']->id,
            'action' => 'schedule_reminder',
        ]);
    }

    /** R04-ish: baris yang sudah diklaim tidak bisa diklaim lagi - penjaga dedup di level baris, bukan cuma withoutOverlapping(). */
    public function test_a_claimed_delivery_cannot_be_claimed_twice(): void
    {
        $this->enableReminders();
        $survey = $this->survey(['state' => 'scheduled', 'surveyor_id' => $this->team['surveyor']->id, 'scheduled_at' => now()->addHours(6)]);
        app(SurveyReminderService::class)->replan($survey);
        $delivery = SurveyReminderDelivery::query()->where('survey_id', $survey->id)->sole();

        $first = app(SurveyReminderService::class)->claim($delivery->id);
        $second = app(SurveyReminderService::class)->claim($delivery->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    /** R05-ish: reschedule ke jadwal/surveyor baru membatalkan delivery versi lama, bukan mengirim keduanya. */
    public function test_reschedule_cancels_old_version_and_creates_new_one(): void
    {
        $this->enableReminders();
        $survey = $this->survey(['state' => 'requested']);
        Sanctum::actingAs($this->team['manager']);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk();
        $oldDelivery = SurveyReminderDelivery::query()->where('survey_id', $survey->id)->sole();

        $this->patchJson('/api/v1/surveys/'.$survey->id.'/reschedule-assignment', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertOk();

        $this->assertSame('cancelled', $oldDelivery->fresh()->status);
        $newDelivery = SurveyReminderDelivery::query()
            ->where('survey_id', $survey->id)->where('status', 'pending')->sole();
        $this->assertGreaterThan($oldDelivery->schedule_revision, $newDelivery->schedule_revision);
    }

    /** R06-ish: unassign/cancel/start/result membatalkan pending - tidak ada pengingat "sebelum jadwal" untuk survey yang sudah tidak scheduled. */
    public function test_unassign_and_cancel_stop_pending_reminders(): void
    {
        $this->enableReminders();

        $survey = $this->survey(['state' => 'requested']);
        Sanctum::actingAs($this->team['manager']);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk();
        $this->assertDatabaseHas('survey_reminder_deliveries', ['survey_id' => $survey->id, 'status' => 'pending']);

        $this->patchJson('/api/v1/surveys/'.$survey->id.'/unassign', [])->assertOk();
        $this->assertDatabaseMissing('survey_reminder_deliveries', ['survey_id' => $survey->id, 'status' => 'pending']);

        // Assign ulang lalu cancel - jalur berbeda, sama-sama harus membatalkan pending.
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk();
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/cancel', [])->assertOk();
        $this->assertDatabaseMissing('survey_reminder_deliveries', ['survey_id' => $survey->id, 'status' => 'pending']);
    }

    /** R07-ish: push gagal/tidak tersedia tidak membatalkan notifikasi in-app - in-app adalah bukti utama, push cuma percobaan tambahan. */
    public function test_push_failure_does_not_prevent_in_app_notification(): void
    {
        $this->mock(WebPushService::class)->shouldReceive('sendToUsers')->andThrow(new \RuntimeException('no subscription'));
        $this->enableReminders();
        $survey = $this->survey(['state' => 'scheduled', 'surveyor_id' => $this->team['surveyor']->id, 'scheduled_at' => now()->addHours(6)]);
        app(SurveyReminderService::class)->replan($survey);
        $delivery = SurveyReminderDelivery::query()->where('survey_id', $survey->id)->sole();

        $this->travelTo($delivery->due_at);
        $this->artisan('surveys:dispatch-due-reminders')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame('notified', $delivery->status);
        $this->assertNotNull($delivery->notification_id);
        $this->assertSame('failed', $delivery->push_status);
    }

    /** R09-ish: penerima yang soft-deleted sebelum due tidak diberi tahu - re-validasi kelayakan wajib sebelum kirim, bukan cuma saat delivery dibuat. */
    public function test_deleted_recipient_before_due_gets_no_notification(): void
    {
        $this->enableReminders();
        $survey = $this->survey(['state' => 'scheduled', 'surveyor_id' => $this->team['surveyor']->id, 'scheduled_at' => now()->addHours(6)]);
        app(SurveyReminderService::class)->replan($survey);
        $delivery = SurveyReminderDelivery::query()->where('survey_id', $survey->id)->sole();

        $this->team['surveyor']->delete();

        $this->travelTo($delivery->due_at);
        $this->artisan('surveys:dispatch-due-reminders')->assertSuccessful();

        $delivery->refresh();
        $this->assertSame('expired', $delivery->status);
        $this->assertNull($delivery->notification_id);
    }

    /** R11-ish: menonaktifkan setting membatalkan SEMUA pending, bukan menunggu masing-masing due lalu di-skip diam-diam. */
    public function test_disabling_setting_cancels_all_pending_deliveries(): void
    {
        $setting = $this->enableReminders();
        $survey = $this->survey(['state' => 'scheduled', 'surveyor_id' => $this->team['surveyor']->id, 'scheduled_at' => now()->addHours(6)]);
        app(SurveyReminderService::class)->replan($survey);
        $this->assertDatabaseHas('survey_reminder_deliveries', ['survey_id' => $survey->id, 'status' => 'pending']);

        $setting->update(['enabled' => false]);
        app(SurveyReminderService::class)->replanForSettingChange($setting->fresh());

        $this->assertDatabaseHas('survey_reminder_deliveries', ['survey_id' => $survey->id, 'status' => 'cancelled']);
    }

    /** Setting nonaktif = default rilis pertama: replan() tidak membuat delivery apa pun sampai diaktifkan eksplisit. */
    public function test_reminders_disabled_by_default_creates_no_delivery(): void
    {
        $survey = $this->survey(['state' => 'requested']);
        Sanctum::actingAs($this->team['manager']);
        $this->patchJson('/api/v1/surveys/'.$survey->id.'/assign', [
            'surveyor_id' => $this->team['surveyor']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk();

        $this->assertDatabaseCount('survey_reminder_deliveries', 0);
    }

    /** Super Admin bisa lihat & ubah setting; preview tidak menulis DB atau memanggil push. */
    public function test_super_admin_can_manage_setting_and_preview_is_read_only(): void
    {
        $survey = $this->survey(['state' => 'scheduled', 'surveyor_id' => $this->team['surveyor']->id, 'scheduled_at' => now()->addDay()]);
        Sanctum::actingAs($this->superAdmin);

        $this->getJson('/api/v1/master-data/survey-reminder-settings')->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->postJson('/api/v1/master-data/survey-reminder-settings/preview', [
            'enabled' => true, 'lead_minutes' => 300,
        ])->assertOk()->assertJsonPath('data.affected_count', 1);
        $this->assertDatabaseCount('survey_reminder_deliveries', 0);

        $this->putJson('/api/v1/master-data/survey-reminder-settings', [
            'enabled' => true, 'lead_minutes' => 300,
        ])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertDatabaseHas('survey_reminder_settings', ['key' => 'default', 'enabled' => true, 'lead_minutes' => 300]);
    }

    /** Manager/surveyor tidak boleh membuka konfigurasi global. */
    public function test_manager_cannot_read_or_write_global_setting(): void
    {
        Sanctum::actingAs($this->team['manager']);
        $this->getJson('/api/v1/master-data/survey-reminder-settings')->assertForbidden();
        $this->putJson('/api/v1/master-data/survey-reminder-settings', ['enabled' => true, 'lead_minutes' => 60])->assertForbidden();
    }

    private function user(UserRole $role, ?string $team = null, ?int $account = null): User
    {
        $number = DB::table('users')->count() + 1;
        $id = DB::table('users')->insertGetId([
            'name' => $role->value.' '.$team.' '.$number,
            'email' => 'reminder-fixture-'.$number.'@example.test', 'password' => 'unused',
            'role' => $role->value, 'survey_team' => $team, 'account_id' => $account,
        ]);

        return User::findOrFail($id);
    }

    private function survey(array $attributes = []): Survey
    {
        $consultation = DB::table('consultations')->insertGetId([
            'consultation_id' => 'REMINDER-FIXTURE-'.(DB::table('consultations')->count() + 1),
            'client_name' => 'Client Reminder', 'account_id' => $this->team['account'],
            'account_group' => 'A', 'created_by' => $this->superAdmin->id ?? $this->team['manager']->id,
        ]);
        $id = DB::table('surveys')->insertGetId(array_merge([
            'consultation_id' => $consultation, 'active_key' => $consultation,
            'account_id' => $this->team['account'], 'requested_by' => $this->team['manager']->id,
            'state' => 'requested', 'requested_at' => now(), 'schedule_revision' => 0,
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
