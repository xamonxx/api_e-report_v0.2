<?php

namespace App\Events;

use App\Models\Consultation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConsultationNotesChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $noteIds
     */
    public function __construct(
        public Consultation $consultation,
        public string $action,
        public array $noteIds,
        public int $actorId,
    ) {
    }

    public function broadcastOn(): array
    {
        return collect(ConsultationNoteCreated::participantsFor($this->consultation))
            ->map(fn (int $userId) => new PrivateChannel("consultation-notes.user.{$userId}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'consultation-notes.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'consultationId' => $this->consultation->id,
            'action' => $this->action,
            'noteIds' => $this->noteIds,
            'actorId' => $this->actorId,
        ];
    }
}
