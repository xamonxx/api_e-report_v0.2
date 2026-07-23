<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $unreadSurveys = $surveyNotifications->whereNull('read_at')->count();

        // `unread_surveys` dihitung dari koleksi yang memang sudah diambil di
        // atas (gratis, dan lebih segar daripada cache). Karena itu total
        // disusun ulang dari komponennya - `initialTotalAlerts` sudah memuat
        // hitungan survey versi cache, memakainya akan menghitung dua kali.
        return response()->json([
            'unread_notes' => $summary['unreadNotesCount'],
            'upcoming_reminders' => $summary['upcomingRemindersCount'],
            'unread_surveys' => $unreadSurveys,
            'pending_survey_requests' => $summary['pendingSurveyRequests'],
            'total' => $summary['unreadNotesCount'] + $summary['upcomingRemindersCount'] + $unreadSurveys,
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
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function markNoteRead(ConsultationNote $note): JsonResponse
    {
        $user = Auth::user();

        // Otorisasi via Policy: cek akses terhadap konsultasi terkait
        $consultation = $note->consultation;
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

    public function clearAll(): JsonResponse
    {
        $user = Auth::user();

        $cleared = DB::transaction(function () use ($user) {
            $notes = ConsultationNote::query()
                ->where('is_read', false)
                ->where('user_id', '!=', $user->id)
                ->whereHas('consultation', fn ($query) => $query->forUser($user))
                ->update(['is_read' => true]);

            $reminders = Reminder::query()
                ->forUser($user)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            $surveys = SurveyNotification::query()
                ->where('user_id', $user->id)
                ->delete();

            return $notes + $reminders + $surveys;
        });

        $this->notificationSummaryService->forgetForUser((int) $user->id);

        return response()->json([
            'success' => true,
            'cleared' => $cleared,
        ]);
    }
}
