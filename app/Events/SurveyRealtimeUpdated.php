<?php

namespace App\Events;

use App\Models\Survey;
use App\Models\SurveyNotification;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\NotificationSummaryService;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurveyRealtimeUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Survey $survey,
        public string $action,
        public string $message,
    ) {
        $this->storeNotifications();
    }

    private function storeNotifications(): void
    {
        $recipients = match ($this->action) {
            'request_created', 'started' => User::query()
                ->whereIn('role', [UserRole::ManagerSurveyor->value, UserRole::SuperAdmin->value])
                ->pluck('id')->all(),
            'scheduled' => [$this->survey->surveyor_id],
            'completed' => [$this->survey->requested_by, $this->survey->assigned_by],
            'cancelled' => [$this->survey->surveyor_id, $this->survey->requested_by],
            'rescheduled_by_admin' => User::query()
                ->whereIn('role', [UserRole::ManagerSurveyor->value, UserRole::SuperAdmin->value])
                ->pluck('id')->all(),
            'rescheduled_by_manager' => [$this->survey->surveyor_id],
            default => [],
        };

        foreach (array_unique(array_filter($recipients)) as $userId) {
            SurveyNotification::create([
                'survey_id' => $this->survey->id,
                'user_id' => $userId,
                'action' => $this->action,
                'title' => match ($this->action) {
                    'request_created' => 'Request Survey Baru',
                    'scheduled' => 'Survey Dijadwalkan',
                    'rescheduled_by_admin', 'rescheduled_by_manager' => 'Reschedule Survey',
                    'started' => 'Survey Dimulai',
                    'completed' => 'Survey Selesai',
                    'cancelled' => 'Survey Dibatalkan',
                    default => 'Pembaruan Survey',
                },
                'message' => $this->message,
            ]);

            app(NotificationSummaryService::class)->forgetForUser((int) $userId);
        }
    }

    public function broadcastOn(): array
    {
        $channels = [new Channel('survey.managers')];
        if ($this->survey->surveyor_id && $this->action !== 'rescheduled_by_admin') {
            $channels[] = new Channel('survey.surveyor.' . $this->survey->surveyor_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'survey.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'surveyId' => $this->survey->id,
            'action' => $this->action,
            'message' => $this->message,
        ];
    }
}
