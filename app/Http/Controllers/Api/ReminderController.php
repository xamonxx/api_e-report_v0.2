<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReminderRequest;
use App\Models\Consultation;
use App\Models\Reminder;
use App\Services\NotificationSummaryService;
use Illuminate\Http\JsonResponse;

class ReminderController extends Controller
{
    public function __construct(
        private readonly NotificationSummaryService $notificationSummaryService
    ) {}

    /**
     * POST /api/v1/consultations/{consultation}/reminders
     */
    public function store(ReminderRequest $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('addReminder', $consultation);

        $user = auth()->user();
        $validated = $request->validated();

        $reminder = $consultation->reminders()->create([
            'user_id' => $user->id,
            'creator_id' => $user->id,
            'message' => $validated['message'],
            'remind_at' => $validated['remind_at'],
        ]);

        $this->notificationSummaryService->forgetForUser($user->id);

        return response()->json([
            'data' => $reminder->load(['user', 'creator']),
            'message' => 'Pengingat berhasil dibuat.',
        ], 201);
    }

    /**
     * DELETE /api/v1/consultations/{consultation}/reminders/{reminder}
     */
    public function destroy(Consultation $consultation, Reminder $reminder): JsonResponse
    {
        if ($reminder->consultation_id !== $consultation->id) {
            return response()->json(['message' => 'Pengingat tidak ditemukan.'], 404);
        }

        $this->authorize('delete', $reminder);

        $reminder->delete();
        $this->notificationSummaryService->forgetForUser((int) auth()->id());
        if ($reminder->user_id && (int) $reminder->user_id !== (int) auth()->id()) {
            $this->notificationSummaryService->forgetForUser((int) $reminder->user_id);
        }
        if ($reminder->creator_id && (int) $reminder->creator_id !== (int) auth()->id()) {
            $this->notificationSummaryService->forgetForUser((int) $reminder->creator_id);
        }

        return response()->json([
            'message' => 'Pengingat berhasil dihapus.',
        ]);
    }
}
