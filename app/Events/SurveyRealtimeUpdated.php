<?php

namespace App\Events;

use App\Models\Survey;
use App\Models\SurveyNotification;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\NotificationSummaryService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SurveyRealtimeUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int|null>  $extraRecipients  penerima tambahan di luar aturan
     *                                            default, mis. surveyor lama yang
     *                                            digantikan saat reschedule.
     */
    public function __construct(
        public Survey $survey,
        public string $action,
        public string $message,
        public array $extraRecipients = [],
    ) {
        $this->storeNotifications();
    }

    /**
     * Siapa yang menerima kabar untuk sebuah aksi survey.
     *
     * Dipakai bersama notifikasi in-app dan Web Push, supaya keduanya tidak
     * pernah menyasar orang yang berbeda.
     *
     * @param  array<int|null>  $extraRecipients
     * @return list<int>
     */
    public static function recipientsFor(Survey $survey, string $action, array $extraRecipients = []): array
    {
        $team = $survey->account?->account_group;
        $managers = fn () => User::query()
            ->where(function ($query) use ($team) {
                $query->where('role', UserRole::SuperAdmin->value)
                    ->orWhere(fn ($query) => $query->where('role', UserRole::ManagerSurveyor->value)
                        ->whereIn('survey_team', ['A', 'B', 'C', 'D', 'E', 'F'])
                        ->where('survey_team', $team ?? '__none__'));
            })
            ->pluck('id')->all();

        $recipients = match ($action) {
            'request_created', 'started' => $managers(),
            'scheduled' => [$survey->surveyor_id],
            'completed' => array_merge(
                [$survey->requested_by, $survey->assigned_by, $survey->surveyor_id],
                $managers(),
            ),
            'cancelled' => array_merge([$survey->surveyor_id, $survey->requested_by], $managers()),
            'rescheduled_by_admin' => $managers(),
            'rescheduled_by_manager' => [$survey->surveyor_id],
            'unassigned' => $managers(),
            'maps_updated' => array_merge([$survey->requested_by, $survey->surveyor_id], $managers()),
            default => [],
        };

        $recipientIds = array_values(array_unique(array_filter(
            array_merge($recipients, $extraRecipients),
            fn ($id) => (int) $id > 0
        )));

        return User::query()->whereKey($recipientIds)->get()
            ->filter(fn (User $user) => Survey::query()->visibleTo($user)->whereKey($survey->id)->exists())
            ->map(fn (User $user) => (int) $user->id)->values()->all();
    }

    /**
     * Judul notifikasi per aksi.
     */
    public static function titleFor(string $action): string
    {
        return match ($action) {
            'request_created' => 'Request Survey Baru',
            'scheduled' => 'Survey Dijadwalkan',
            'rescheduled_by_admin', 'rescheduled_by_manager' => 'Reschedule Survey',
            'started' => 'Survey Dimulai',
            'completed' => 'Survey Selesai',
            'cancelled' => 'Survey Dibatalkan',
            'unassigned' => 'Penugasan Dilepas',
            'maps_updated' => 'Link Maps Survey Diperbarui',
            default => 'Pembaruan Survey',
        };
    }

    private function storeNotifications(): void
    {
        $title = self::titleFor($this->action);

        foreach (self::recipientsFor($this->survey, $this->action, $this->extraRecipients) as $userId) {
            SurveyNotification::create([
                'survey_id' => $this->survey->id,
                'user_id' => $userId,
                'action' => $this->action,
                'title' => $title,
                'message' => $this->message,
            ]);

            app(NotificationSummaryService::class)->forgetForUser((int) $userId);
        }
    }

    public function broadcastOn(): array
    {
        // Channel privat: pesan memuat nama klien, wilayah, dan jadwal, jadi
        // harus lewat otorisasi routes/channels.php.
        $channels = [new PrivateChannel('survey.managers')];
        $team = $this->survey->account?->account_group;
        if (in_array($team, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
            $channels[] = new PrivateChannel('survey.managers.' . $team);
        }
        if ($this->survey->account_id) {
            $channels[] = new PrivateChannel('survey.account.' . $this->survey->account_id);
        }
        // Team F: surveyor pinjaman (GACONG) tetap berhak atas kanal
        // pribadinya sendiri walau timnya beda dari akun survey. Tim lain
        // (A-E) tetap wajib kecocokan tim KECUALI ada izin pinjam aktif
        // (SurveyLoanApproval, persetujuan Super Admin) - tanpa ini surveyor
        // pinjaman lintas tim di luar Team F tidak pernah dapat update
        // realtime walau notifikasi in-app-nya sendiri sudah benar tersimpan.
        $surveyor = $this->survey->surveyor_id ? User::find($this->survey->surveyor_id) : null;
        if ($surveyor && $surveyor->hasSurveyTeam()
            && ($surveyor->survey_team === $team || $team === 'F' || $this->survey->hasActiveLoanApprovalFor($surveyor->id))
            && $this->action !== 'rescheduled_by_admin') {
            $channels[] = new PrivateChannel('survey.surveyor.' . $this->survey->surveyor_id);
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
