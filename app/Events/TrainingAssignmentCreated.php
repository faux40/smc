<?php

namespace App\Events;

use App\Models\TrainingAssignment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingAssignmentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly TrainingAssignment $trainingAssignment,
        public readonly ?string $actorId = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->trainingAssignment->org_id)];
    }

    public function broadcastWith(): array
    {
        $ta = $this->trainingAssignment;

        $sources = $ta->relationLoaded('activeSources')
            ? $ta->activeSources
            : $ta->activeSources()->get();

        return [
            'id' => $ta->id,
            'user_id' => $ta->user_id,
            'training_id' => $ta->training_id,
            'name' => $ta->name,
            'expires_at' => $ta->expires_at?->toDateString(),
            'last_completed_at' => $ta->last_completed_at?->toDateString(),
            'active_sources' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'sourceable_type' => $s->sourceable_type,
                'sourceable_id' => $s->sourceable_id,
                'added_at' => $s->added_at->toISOString(),
            ])->values()->all(),
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
