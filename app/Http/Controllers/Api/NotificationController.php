<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceNotification;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\Reminder;
use App\Models\SurveyNotification;
use App\Services\NotificationSummaryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationSummaryService $notificationSummaryService
    ) {
    }

    public function unreadCount(): JsonResponse
    {
        $user = Auth::user();

        // Semua angka diambil dari satu payload yang sudah di-cache 2 menit;
        // sebelumnya hitungan survey dijalankan mentah pada setiap polling.
        $summary = $this->notificationSummaryService->getCountsForUser($user);

        return response()->json([
            'unread_notes' => $summary['unreadNotesCount'],
            'upcoming_reminders' => $summary['upcomingRemindersCount'],
            'unread_surveys' => $summary['unreadSurveysCount'],
            'unread_attendances' => $summary['unreadAttendancesCount'],
            'pending_survey_requests' => $summary['pendingSurveyRequests'],
            'total' => $summary['initialTotalAlerts'],
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function summary(): JsonResponse
    {
        $user = Auth::user();
        $summary = $this->notificationSummaryService->getForUser($user);
        $surveyNotifications = SurveyNotification::query()
            ->with([
                'survey.consultation:id,consultation_id,client_name,district,city,province',
                'survey.surveyor:id,name',
            ])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(30)
            ->get();
        $unreadSurveys = SurveyNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
        $attendanceNotifications = $user->isSuperAdmin()
            ? AttendanceNotification::query()
                ->where('user_id', $user->id)
                ->latest()
                ->limit(30)
                ->get()
            : collect();
        $unreadAttendances = $user->isSuperAdmin()
            ? AttendanceNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count()
            : 0;

        // List survey sengaja dibatasi 30 item terbaru agar panel tetap ringan,
        // tetapi angka unread harus menghitung seluruh notifikasi yang belum
        // dibaca. Kalau dihitung dari koleksi 30 item, badge bisa turun saat
        // dropdown dibuka walau unread lama masih ada.
        return response()->json([
            'unread_notes' => $summary['unreadNotesCount'],
            'upcoming_reminders' => $summary['upcomingRemindersCount'],
            'unread_surveys' => $unreadSurveys,
            'unread_attendances' => $unreadAttendances,
            'pending_survey_requests' => $summary['pendingSurveyRequests'],
            'total' => $summary['unreadNotesCount'] + $summary['upcomingRemindersCount'] + $unreadSurveys + $unreadAttendances,
            'notes' => $summary['unreadNotes']->map(function (ConsultationNote $note) {
                return [
                    'id' => $note->id,
                    'author_name' => $note->user?->name ?? 'Tim',
                    'author_initial' => Str::upper(Str::substr($note->user?->name ?? 'TM', 0, 2)),
                    'body' => Str::limit((string) $note->body, 120),
                    'consultation_name' => $note->consultation?->client_name ?? '-',
                    'consultation_url' => $note->consultation ? "/consultations/{$note->consultation->id}" : null,
                    'created_human' => $note->created_at?->diffForHumans(),
                ];
            })->values(),
            'reminders' => $summary['activeReminders']->map(function (Reminder $reminder) use ($user) {
                return [
                    'id' => $reminder->id,
                    'message' => Str::limit((string) $reminder->message, 140),
                    'consultation_name' => $reminder->consultation?->client_name ?? '-',
                    'consultation_url' => "/consultations/{$reminder->consultation_id}",
                    'owner_name' => $reminder->user && $reminder->user->id !== $user->id ? $reminder->user->name : null,
                    'overdue' => $reminder->remind_at?->isPast() ?? false,
                    'remind_human' => $reminder->remind_at?->diffForHumans(),
                    'remind_label' => $reminder->remind_at?->format('d M H:i'),
                    'mark_read_url' => route('api.notifications.reminders.read', $reminder),
                ];
            })->values(),
            'surveys' => $surveyNotifications
                ->map(function (SurveyNotification $notification) {
                    $survey = $notification->survey;
                    $consultation = $survey?->consultation;
                    $location = collect([
                        $consultation?->district,
                        $consultation?->city,
                        $consultation?->province,
                    ])->filter()->implode(', ');
                    $schedule = $survey?->scheduled_at
                        ?? ($survey?->requested_date
                            ? Carbon::parse($survey->requested_date->format('Y-m-d').' '.($survey->requested_time ?? '00:00'))
                            : null);

                    return [
                        'id' => $notification->id,
                        'type' => $notification->action,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'is_read' => $notification->read_at !== null,
                        'created_human' => $notification->created_at?->diffForHumans(),
                        'survey_id' => $survey?->id,
                        'survey_url' => '/surveys',
                        'state' => $survey?->state,
                        'client_name' => $consultation?->client_name ?: 'Konsumen tanpa nama',
                        'consultation_code' => $consultation?->consultation_id,
                        'location' => $location ?: null,
                        'schedule_label' => $schedule?->translatedFormat('d M Y, H:i'),
                        'surveyor_name' => $survey?->surveyor?->name,
                    ];
                }),
            'attendances' => $attendanceNotifications
                ->map(fn (AttendanceNotification $notification) => [
                    'id' => $notification->id,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'is_read' => $notification->read_at !== null,
                    'created_human' => $notification->created_at?->diffForHumans(),
                    'admin_name' => $notification->admin_name,
                    'account_name' => $notification->account_name,
                    'report_date' => $notification->report_date?->toDateString(),
                    'report_date_label' => $notification->report_date?->translatedFormat('d M Y'),
                    'report_category' => $notification->report_category,
                    'report_category_label' => $this->attendanceCategoryLabel((string) $notification->report_category),
                    'url' => '/report-attendances',
                ]),
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function markNoteRead(ConsultationNote $note): JsonResponse
    {
        $user = Auth::user();

        // Otorisasi via Policy: cek akses terhadap konsultasi terkait
        $consultation = Consultation::query()->find($note->consultation_id);
        if (!$consultation || Gate::denies('view', $consultation)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $note->update(['is_read' => true]);
        $this->notificationSummaryService->forgetForUser($user->id);
        
        return response()->json(['success' => true]);
    }

    public function markReminderRead(Reminder $reminder): JsonResponse
    {
        $user = Auth::user();

        // Otorisasi via Policy
        if (Gate::denies('markAsRead', $reminder)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $reminder->update(['is_read' => true]);
        $this->notificationSummaryService->forgetForUser($user->id);
        
        return response()->json(['success' => true]);
    }

    public function markSurveyRead(SurveyNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === Auth::id(), 403);
        $notification->update(['read_at' => now()]);
        $this->notificationSummaryService->forgetForUser((int) Auth::id());

        return response()->json(['success' => true]);
    }

    public function deleteSurvey(SurveyNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === Auth::id(), 403);
        $notification->delete();
        $this->notificationSummaryService->forgetForUser((int) Auth::id());

        return response()->json(['success' => true]);
    }

    public function markAttendanceRead(AttendanceNotification $notification): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->isSuperAdmin() && $notification->user_id === $user->id, 403);
        $notification->update(['read_at' => now()]);
        $this->notificationSummaryService->forgetForUser((int) $user->id);

        return response()->json(['success' => true]);
    }

    public function deleteAttendance(AttendanceNotification $notification): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->isSuperAdmin() && $notification->user_id === $user->id, 403);
        $notification->delete();
        $this->notificationSummaryService->forgetForUser((int) $user->id);

        return response()->json(['success' => true]);
    }

    public function clearAll(): JsonResponse
    {
        $user = Auth::user();

        $cleared = DB::transaction(function () use ($user) {
            $notes = 0;

            if ($user->isAdmin() || $user->isSuperAdmin()) {
                $noteQuery = ConsultationNote::query()
                    ->where('is_read', false)
                    ->where('user_id', '!=', $user->id);

                if ($user->isAdmin()) {
                    $noteQuery->whereHas(
                        'consultation',
                        fn ($query) => $query->where('account_id', $user->account_id)
                    );
                }

                $notes = $noteQuery->update(['is_read' => true]);
            }

            $reminders = Reminder::query()
                ->forUser($user)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            $surveys = SurveyNotification::query()
                ->where('user_id', $user->id)
                ->delete();

            $attendances = AttendanceNotification::query()
                ->where('user_id', $user->id)
                ->delete();

            return $notes + $reminders + $surveys + $attendances;
        });

        $this->notificationSummaryService->forgetForUser((int) $user->id);

        return response()->json([
            'success' => true,
            'cleared' => $cleared,
        ]);
    }

    private function attendanceCategoryLabel(string $category): string
    {
        return match ($category) {
            'ada_wa' => 'Ada WA',
            'nol_wa' => '0 WA',
            'libur_susulan' => 'Libur Susulan',
            default => 'Absensi',
        };
    }
}
