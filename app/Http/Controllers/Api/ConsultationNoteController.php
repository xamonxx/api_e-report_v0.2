<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationNoteRequest;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use Illuminate\Http\JsonResponse;

class ConsultationNoteController extends Controller
{
    /**
     * POST /api/v1/consultations/{consultation}/notes
     */
    public function store(ConsultationNoteRequest $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('addNote', $consultation);

        $user = auth()->user();

        $note = $consultation->timelineNotes()->create([
            'user_id' => $user->id,
            'body' => $request->validated('body'),
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
