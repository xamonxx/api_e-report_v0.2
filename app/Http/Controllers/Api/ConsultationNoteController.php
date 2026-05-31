<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationNoteRequest;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ConsultationNoteController extends Controller
{
    /**
     * POST /api/v1/consultations/{consultation}/notes
     */
    public function store(ConsultationNoteRequest $request, Consultation $consultation, WebPushService $webPush): JsonResponse
    {
        $this->authorize('addNote', $consultation);

        $user = auth()->user();

        $note = $consultation->timelineNotes()->create([
            'user_id' => $user->id,
            'body' => $request->validated('body'),
        ]);

        // Push to everyone who can see this consultation (account admins +
        // super admins), except the author of the note.
        $recipientIds = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($consultation) {
                $q->where('role', UserRole::SuperAdmin)
                    ->orWhere(fn ($q2) => $q2->where('role', UserRole::Admin)
                        ->where('account_id', $consultation->account_id));
            })
            ->pluck('id')
            ->all();

        $webPush->sendToUsers($recipientIds, [
            'title' => 'Catatan baru — '.$consultation->client_name,
            'body' => $user->name.': '.Str::limit($note->body, 90),
            'url' => '/consultations/'.$consultation->id,
            'tag' => 'note-'.$consultation->id,
        ]);

        return response()->json([
            'data' => $note->load('user'),
            'message' => 'Catatan berhasil ditambahkan.',
        ], 201);
    }

    /**
     * DELETE /api/v1/consultations/{consultation}/notes/{note}
     */
    public function destroy(Consultation $consultation, ConsultationNote $note): JsonResponse
    {
        if ($note->consultation_id !== $consultation->id) {
            return response()->json(['message' => 'Catatan tidak ditemukan.'], 404);
        }

        $this->authorize('delete', $note);

        $note->delete();

        return response()->json([
            'message' => 'Catatan berhasil dihapus.',
        ]);
    }
}
