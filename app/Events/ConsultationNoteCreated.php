<?php

namespace App\Events;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ConsultationNoteCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public ConsultationNote $note,
        public array $recipientIds,
    ) {
        $this->note->loadMissing([
            'consultation:id,client_name',
            'user:id,name',
        ]);
    }

    /**
     * Satu sumber penerima untuk realtime dan Web Push. Catatan hanya boleh
     * keluar ke super admin dan admin pemilik akun konsultasi.
     *
     * @return list<int>
     */
    public static function recipientsFor(Consultation $consultation, int $authorId): array
    {
        return array_values(array_filter(
            self::participantsFor($consultation),
            fn (int $userId) => $userId !== $authorId
        ));
    }

    /**
     * @return list<int>
     */
    public static function participantsFor(Consultation $consultation): array
    {
        return User::query()
            ->where(function ($query) use ($consultation) {
                $query->where('role', UserRole::SuperAdmin->value)
                    ->orWhere(function ($adminQuery) use ($consultation) {
                        $adminQuery
                            ->where('role', UserRole::Admin->value)
                            ->where('account_id', $consultation->account_id);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function broadcastOn(): array
    {
        return collect($this->recipientIds)
            ->map(fn (int $userId) => new PrivateChannel("consultation-notes.user.{$userId}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'consultation-note.created';
    }

    public function broadcastWith(): array
    {
        return [
            'noteId' => $this->note->id,
            'consultationId' => $this->note->consultation_id,
            'consultationName' => $this->note->consultation?->client_name ?? 'Konsumen',
            'authorName' => $this->note->user?->name ?? 'Tim',
            'body' => Str::limit((string) $this->note->body, 140),
            'createdAt' => $this->note->created_at?->toIso8601String(),
        ];
    }
}
