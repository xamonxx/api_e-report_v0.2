<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsultationNote;
use App\Models\Reminder;
use App\Services\NotificationSummaryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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

        $summary = $this->notificationSummaryService->getCountsForUser($user);

        return response()->json([
            'unread_notes' => $summary['unreadNotesCount'],
            'upcoming_reminders' => $summary['upcomingRemindersCount'],
            'total' => $summary['initialTotalAlerts'],
            'timestamp' => Carbon::now()->toIso8601String(),
        ]);
    }

    public function summary(): JsonResponse
    {
        $user = Auth::user();
        $summary = $this->notificationSummaryService->getForUser($user);

        return response()->json([
            'unread_notes' => $summary['unreadNotesCount'],
            'upcoming_reminders' => $summary['upcomingRemindersCount'],
            'total' => $summary['initialTotalAlerts'],
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
}
