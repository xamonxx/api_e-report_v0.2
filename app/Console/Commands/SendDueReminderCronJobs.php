<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\ReminderCronJob;
use App\Models\User;
use App\Services\WebPushService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Throwable;

class SendDueReminderCronJobs extends Command
{
    protected $signature = 'reminders:send-due-cron-jobs';

    protected $description = 'Kirim web push untuk cron job pengingat yang jatuh tempo.';

    public function handle(WebPushService $webPush): int
    {
        $now = now();
        $minuteStart = $now->copy()->startOfMinute()->format('H:i:s');
        $minuteEnd = $now->copy()->endOfMinute()->format('H:i:s');
        $today = $now->toDateString();

        $dueJobs = ReminderCronJob::query()
            ->where('is_active', true)
            ->whereBetween('time_of_day', [$minuteStart, $minuteEnd])
            ->where(function ($query) use ($today) {
                $query->whereNull('last_sent_date')
                    ->orWhereDate('last_sent_date', '<>', $today);
            })
            ->orderBy('id')
            ->get();

        if ($dueJobs->isEmpty()) {
            $this->info('Tidak ada cron job pengingat yang jatuh tempo.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($dueJobs as $job) {
            try {
                match ($job->type) {
                    ReminderCronJob::TYPE_ATTENDANCE_REMINDER => $this->sendAttendanceReminder(
                        $job,
                        $webPush,
                        $now,
                        $today
                    ),
                    default => $this->warn("Tipe job tak dikenal: {$job->type} (#{$job->id}), dilewati."),
                };

                // WebPushService bersifat fail-open dan menangani kegagalan delivery internal.
                // Kolom ini hanya tidak diisi bila terjadi exception di luar kontrak service.
                $job->forceFill(['last_sent_date' => $today])->save();
            } catch (Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error("Cron job #{$job->id} gagal diproses: {$exception->getMessage()}");
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info("Memproses {$dueJobs->count()} cron job pengingat.");

        return self::SUCCESS;
    }

    private function sendAttendanceReminder(
        ReminderCronJob $job,
        WebPushService $webPush,
        CarbonInterface $now,
        string $today
    ): void {
        $pendingAdminIds = User::query()
            ->where('role', UserRole::Admin)
            ->whereDoesntHave(
                'reportAttendances',
                fn ($query) => $query->whereDate('report_date', $today)
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($pendingAdminIds === []) {
            return;
        }

        $dateLabel = $now->translatedFormat('l, d F Y');
        $template = $job->message ?: 'Jangan lupa lakukan absensi hari ini, {tanggal}.';

        $webPush->sendToUsers($pendingAdminIds, [
            'title' => $job->name ?: 'Pengingat Absensi',
            'body' => str_replace('{tanggal}', $dateLabel, $template),
            'url' => '/report-attendances',
            'tag' => "reminder-cron-{$job->id}-{$today}",
        ]);
    }
}
