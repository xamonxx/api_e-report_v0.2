<?php

namespace App\Console\Commands;

use App\Models\Reminder;
use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PushDueReminders extends Command
{
    protected $signature = 'reminders:push-due';

    protected $description = 'Send a web push for reminders that have just become due (once each).';

    public function handle(WebPushService $webPush): int
    {
        $due = Reminder::query()
            ->whereNull('pushed_at')
            ->where('is_read', false)
            ->where('remind_at', '<=', now())
            ->with('consultation:id,client_name')
            ->limit(200)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No due reminders to push.');
            return self::SUCCESS;
        }

        foreach ($due as $reminder) {
            $clientName = $reminder->consultation?->client_name ?? 'Konsultasi';

            $webPush->sendToUsers([$reminder->user_id], [
                'title' => 'Pengingat — '.$clientName,
                'body' => Str::limit($reminder->message, 90),
                'url' => '/consultations/'.$reminder->consultation_id,
                'tag' => 'reminder-'.$reminder->id,
            ]);

            $reminder->forceFill(['pushed_at' => now()])->save();
        }

        $this->info("Pushed {$due->count()} due reminder(s).");
        return self::SUCCESS;
    }
}
