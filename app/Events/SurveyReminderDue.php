<?php

namespace App\Events;

use App\Models\Survey;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event realtime KHUSUS reminder - beda dari SurveyRealtimeUpdated yang juga
 * menulis SurveyNotification & broadcast ke banyak channel (manager, akun,
 * dst). Reminder cuma perlu memberi tahu SATU penerima (surveyor yang
 * ditugaskan) lewat kanal privatnya sendiri, tanpa efek tulis DB tersembunyi
 * - baris SurveyNotification-nya sudah dibuat eksplisit oleh
 * SurveyReminderService::process() sebelum event ini dilempar.
 */
class SurveyReminderDue implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Survey $survey,
        public int $recipientId,
        public string $message,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('survey.surveyor.'.$this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'survey.reminder-due';
    }

    public function broadcastWith(): array
    {
        return [
            'surveyId' => $this->survey->id,
            'message' => $this->message,
        ];
    }
}
