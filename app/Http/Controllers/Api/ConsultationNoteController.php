<?php

namespace App\Http\Controllers\Api;

use App\Events\ConsultationNoteCreated;
use App\Events\ConsultationNotesChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsultationNoteRequest;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

        $recipientIds = ConsultationNoteCreated::recipientsFor($consultation, (int) $user->id);

        try {
            ConsultationNoteCreated::dispatch($note, $recipientIds);
        } catch (Throwable $exception) {
            Log::warning('Broadcast realtime catatan gagal; catatan tetap tersimpan.', [
                'note_id' => $note->id,
                'consultation_id' => $consultation->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $webPush->sendToUsers($recipientIds, [
            'title' => 'Catatan baru — '.$consultation->client_name,
            'body' => $user->name.': '.Str::limit($note->body, 90),
            'url' => '/consultations/'.$consultation->id,
            'tag' => 'note-'.$note->id,
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
        $this->broadcastChange($consultation, 'deleted', [$note->id]);

        return response()->json([
            'message' => 'Catatan berhasil dihapus.',
        ]);
    }

    public function update(
        ConsultationNoteRequest $request,
        Consultation $consultation,
        ConsultationNote $note
    ): JsonResponse {
        if ((int) $note->consultation_id !== (int) $consultation->id) {
            return response()->json(['message' => 'Catatan tidak ditemukan.'], 404);
        }

        $this->authorize('update', $note);

        $note->update([
            'body' => $request->validated('body'),
        ]);

        $this->broadcastChange($consultation, 'updated', [$note->id]);

        return response()->json([
            'data' => $note->fresh()->load('user'),
            'message' => 'Pesan berhasil diperbarui.',
        ]);
    }

    public function destroySelected(Request $request, Consultation $consultation): JsonResponse
    {
        $this->authorize('addNote', $consultation);

        $data = $request->validate([
            'note_ids' => ['required', 'array', 'min:1', 'max:100'],
            'note_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $noteIds = collect($data['note_ids'])
            ->map(fn ($id) => (int) $id)
            ->values();
        $notes = $consultation->timelineNotes()
            ->whereIn('id', $noteIds)
            ->get();

        if ($notes->count() !== $noteIds->count()) {
            return response()->json(['message' => 'Satu atau lebih pesan tidak ditemukan.'], 404);
        }

        foreach ($notes as $note) {
            $this->authorize('delete', $note);
        }

        DB::transaction(function () use ($notes) {
            foreach ($notes as $note) {
                $note->delete();
            }
        });

        $deletedIds = $notes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->broadcastChange($consultation, 'deleted', $deletedIds);

        return response()->json([
            'deleted' => count($deletedIds),
            'message' => count($deletedIds).' pesan berhasil dihapus.',
        ]);
    }

    public function clear(Consultation $consultation): JsonResponse
    {
        $this->authorize('addNote', $consultation);

        $user = auth()->user();
        $query = $consultation->timelineNotes();

        // Admin hanya membersihkan pesan miliknya. Super admin boleh
        // membersihkan seluruh percakapan konsultasi.
        if (! $user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        }

        $notes = $query->get();

        DB::transaction(function () use ($notes) {
            foreach ($notes as $note) {
                $note->delete();
            }
        });

        $deletedIds = $notes->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($deletedIds !== []) {
            $this->broadcastChange($consultation, 'cleared', $deletedIds);
        }

        return response()->json([
            'deleted' => count($deletedIds),
            'message' => count($deletedIds).' pesan berhasil dibersihkan.',
        ]);
    }

    /**
     * Sinkronkan perubahan chat ke seluruh peserta tanpa membuat aksi utama
     * gagal ketika server realtime sedang tidak tersedia.
     *
     * @param  list<int>  $noteIds
     */
    private function broadcastChange(Consultation $consultation, string $action, array $noteIds): void
    {
        try {
            ConsultationNotesChanged::dispatch(
                $consultation,
                $action,
                $noteIds,
                (int) auth()->id(),
            );
        } catch (Throwable $exception) {
            Log::warning('Broadcast perubahan catatan gagal; perubahan tetap tersimpan.', [
                'consultation_id' => $consultation->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
