<?php

namespace App\Console\Commands;

use App\Models\SurveyReminderDelivery;
use App\Services\SurveyReminderService;
use Illuminate\Console\Command;

/**
 * Dipanggil scheduler tiap menit (routes/console.php,
 * ->everyMinute()->withoutOverlapping() - pola sama seperti reminders:push-due
 * & reminders:send-due-cron-jobs yang sudah ada). Klaim per-baris (row lock
 * di SurveyReminderService::claim()) adalah penjaga konsistensi sebenarnya
 * kalau overlap tetap terjadi (mis. runner sebelumnya lambat) - bukan
 * withoutOverlapping() sendirian.
 */
class DispatchDueSurveyReminders extends Command
{
    protected $signature = 'surveys:dispatch-due-reminders';

    protected $description = 'Proses pengingat survey yang sudah jatuh tempo (in-app + percobaan push sekali).';

    public function handle(SurveyReminderService $reminderService): int
    {
        $due = $reminderService->dueDeliveries(100);

        if ($due->isEmpty()) {
            $this->info('Tidak ada pengingat survey yang jatuh tempo.');

            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($due as $row) {
            $claimed = $reminderService->claim($row->id);
            if (! $claimed) {
                continue; // sudah diambil runner lain / bukan lagi pending
            }
            $reminderService->process($claimed);
            $processed++;
        }

        $notified = SurveyReminderDelivery::query()
            ->whereIn('id', $due->pluck('id'))
            ->where('status', SurveyReminderDelivery::STATUS_NOTIFIED)
            ->count();

        $this->info("Diproses {$processed} pengingat, {$notified} berhasil terkirim in-app.");

        return self::SUCCESS;
    }
}
