<?php

namespace Tests\Feature;

use App\Console\Commands\SendDueReminderCronJobs;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ReminderCronJob;
use App\Models\ReportAttendance;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ReminderCronJobTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

        // Jangan pernah bootstrap koneksi database aplikasi untuk suite ini.
        // Migration roster operasional juga membutuhkan data existing, sehingga
        // fixture minimal SQLite lebih aman daripada RefreshDatabase.
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set([
                'database.default' => 'reminder_cron_testing',
                'database.connections' => [
                    'reminder_cron_testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                        'foreign_key_constraints' => true,
                    ],
                ],
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'broadcasting.default' => 'null',
                'mail.default' => 'array',
            ]);
        });
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $kernel->registerCommand($app->make(SendDueReminderCronJobs::class));

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(13, 0, 30));
        $this->createFixtureSchema();
    }

    public function test_super_admin_can_manage_jobs_and_every_mutation_is_audited(): void
    {
        $superAdmin = $this->createUser(UserRole::SuperAdmin);
        Sanctum::actingAs($superAdmin);

        $created = $this->postJson('/api/v1/master-data/reminder-cron-jobs', [
            'name' => 'Pengingat Absensi Siang',
            'type' => 'hostile_type_is_ignored',
            'time_of_day' => '13:00',
            'message' => 'Segera absen untuk {tanggal}.',
            'is_active' => true,
            'last_sent_date' => '2026-09-01',
        ])->assertCreated()
            ->assertJsonPath('data.type', ReminderCronJob::TYPE_ATTENDANCE_REMINDER)
            ->assertJsonPath('data.time_of_day', '13:00')
            ->assertJsonPath('data.is_active', true);

        $jobId = (int) $created->json('data.id');
        $this->assertDatabaseHas('reminder_cron_jobs', [
            'id' => $jobId,
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'created_by' => $superAdmin->id,
            'last_sent_date' => null,
        ]);

        $this->getJson('/api/v1/master-data/reminder-cron-jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/master-data/reminder-cron-jobs/{$jobId}", [
            'name' => 'Pengingat Absensi Sore',
            'time_of_day' => '15:30',
            'message' => '',
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Pengingat Absensi Sore')
            ->assertJsonPath('data.message', null);

        $this->patchJson("/api/v1/master-data/reminder-cron-jobs/{$jobId}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/master-data/reminder-cron-jobs/{$jobId}")
            ->assertOk();

        $this->assertDatabaseMissing('reminder_cron_jobs', ['id' => $jobId]);
        $logs = AuditLog::query()
            ->where('loggable_type', ReminderCronJob::class)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $logs);
        $this->assertSame(['created', 'updated', 'updated', 'deleted'], $logs->pluck('action')->all());
    }

    public function test_non_super_admin_roles_cannot_read_or_mutate_jobs(): void
    {
        $job = ReminderCronJob::query()->create([
            'name' => 'Protected Job',
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'time_of_day' => '13:00',
            'is_active' => true,
        ]);

        foreach ([UserRole::Admin, UserRole::Surveyor] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $this->getJson('/api/v1/master-data/reminder-cron-jobs')->assertForbidden();
            $this->postJson('/api/v1/master-data/reminder-cron-jobs', $this->validPayload())
                ->assertForbidden();
            $this->putJson("/api/v1/master-data/reminder-cron-jobs/{$job->id}", $this->validPayload())
                ->assertForbidden();
            $this->patchJson("/api/v1/master-data/reminder-cron-jobs/{$job->id}/toggle")
                ->assertForbidden();
            $this->deleteJson("/api/v1/master-data/reminder-cron-jobs/{$job->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('reminder_cron_jobs', [
            'id' => $job->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_command_sends_only_to_admins_without_attendance_and_is_idempotent(): void
    {
        $pendingAdmin = $this->createUser(UserRole::Admin);
        $reportedAdmin = $this->createUser(UserRole::Admin);
        $this->createUser(UserRole::Surveyor);

        ReportAttendance::query()->create([
            'user_id' => $reportedAdmin->id,
            'account_id' => null,
            'report_date' => now()->toDateString(),
            'report_category' => 'ada_wa',
        ]);

        $job = ReminderCronJob::query()->create([
            'name' => 'Pengingat Absensi',
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'time_of_day' => now()->format('H:i'),
            'message' => 'Absensi wajib untuk {tanggal}.',
            'is_active' => true,
        ]);

        $expectedDateLabel = now()->translatedFormat('l, d F Y');
        $this->mock(WebPushService::class, function (MockInterface $mock) use ($pendingAdmin, $expectedDateLabel): void {
            $mock->shouldReceive('sendToUsers')
                ->once()
                ->withArgs(function (array $userIds, array $payload) use ($pendingAdmin, $expectedDateLabel): bool {
                    return $userIds === [$pendingAdmin->id]
                        && $payload['title'] === 'Pengingat Absensi'
                        && $payload['url'] === '/report-attendances'
                        && str_contains($payload['body'], $expectedDateLabel)
                        && ! str_contains($payload['body'], '{tanggal}');
                });
        });

        $this->assertSame(Command::SUCCESS, Artisan::call('reminders:send-due-cron-jobs'), Artisan::output());
        $this->assertSame(Command::SUCCESS, Artisan::call('reminders:send-due-cron-jobs'), Artisan::output());

        $this->assertSame(now()->toDateString(), $job->fresh()->last_sent_date?->toDateString());
    }

    public function test_inactive_or_different_time_jobs_are_not_sent(): void
    {
        $this->createUser(UserRole::Admin);
        ReminderCronJob::query()->create([
            'name' => 'Inactive',
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'time_of_day' => now()->format('H:i'),
            'is_active' => false,
        ]);
        ReminderCronJob::query()->create([
            'name' => 'Later',
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'time_of_day' => now()->addMinutes(30)->format('H:i'),
            'is_active' => true,
        ]);

        $this->mock(WebPushService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendToUsers');
        });

        $this->assertSame(Command::SUCCESS, Artisan::call('reminders:send-due-cron-jobs'), Artisan::output());
        $this->assertDatabaseCount('reminder_cron_jobs', 2);
        $this->assertSame(0, ReminderCronJob::query()->whereNotNull('last_sent_date')->count());
    }

    public function test_unexpected_push_exception_does_not_mark_job_as_sent(): void
    {
        $this->createUser(UserRole::Admin);
        $job = ReminderCronJob::query()->create([
            'name' => 'Failure Semantics',
            'type' => ReminderCronJob::TYPE_ATTENDANCE_REMINDER,
            'time_of_day' => now()->format('H:i'),
            'is_active' => true,
        ]);

        $this->mock(WebPushService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendToUsers')
                ->once()
                ->andThrow(new RuntimeException('Synthetic push failure'));
        });

        $this->assertSame(Command::FAILURE, Artisan::call('reminders:send-due-cron-jobs'), Artisan::output());
        $this->assertNull($job->fresh()->last_sent_date);
    }

    /** @return array{name:string, time_of_day:string, message:string|null, is_active:bool} */
    private function validPayload(): array
    {
        return [
            'name' => 'Valid Reminder',
            'time_of_day' => '13:00',
            'message' => null,
            'is_active' => true,
        ];
    }

    private function createUser(UserRole $role): User
    {
        static $sequence = 0;
        $sequence++;

        $user = new User([
            'name' => $role->label().' '.$sequence,
            'email' => "{$role->value}-{$sequence}@cron.test",
            'password' => Hash::make('CronReminder!123'),
        ]);
        $user->role = $role;
        $user->save();

        return $user->fresh();
    }

    private function createFixtureSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default(UserRole::Admin->value);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->char('survey_team', 1)->nullable();
            $table->string('primary_color')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->date('report_date');
            $table->string('report_category')->nullable();
            $table->timestamps();
        });

        Schema::create('reminder_cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('type', 40)->default(ReminderCronJob::TYPE_ATTENDANCE_REMINDER);
            $table->time('time_of_day');
            $table->text('message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_sent_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('loggable_type')->nullable();
            $table->unsignedBigInteger('loggable_id')->nullable();
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }
}
