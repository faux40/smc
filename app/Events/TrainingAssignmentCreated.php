<?php

namespace App\Events;

use App\Models\AssignmentSource;
use App\Models\TrainingAssignment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Created/updated upsert broadcast for a training assignment (peer tabs
 * upsert the row in their store).
 *
 * No SerializesModels (J6): TAs hard-delete by design — break-out
 * converts/deletes them in quick succession — so a serialized model
 * reference would throw ModelNotFoundException in the queue worker
 * whenever the TA vanished before the broadcast job ran. The payload is
 * captured as a plain array at dispatch time instead. That also pins
 * origin_tab correctly: RealtimeOrigin reads the X-Origin-Tab request
 * header, which only exists while the HTTP request is alive — never in
 * the worker.
 */
class TrainingAssignmentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public readonly string $orgId;

    /** Convenience for in-process listeners/tests. */
    public readonly string $trainingAssignmentId;

    /** @var array<string, mixed> */
    public readonly array $payload;

    public function __construct(
        TrainingAssignment $trainingAssignment,
        public readonly ?string $actorId = null,
    ) {
        $sources = $trainingAssignment->relationLoaded('activeSources')
            ? $trainingAssignment->activeSources
            : $trainingAssignment->activeSources()->get();

        $this->orgId = $trainingAssignment->org_id;
        $this->trainingAssignmentId = $trainingAssignment->id;

        $this->payload = [
            'id' => $trainingAssignment->id,
            'user_id' => $trainingAssignment->user_id,
            'training_id' => $trainingAssignment->training_id,
            'name' => $trainingAssignment->name,
            'expires_at' => $trainingAssignment->expires_at?->toDateString(),
            'last_completed_at' => $trainingAssignment->last_completed_at?->toDateString(),
            'active_sources' => $sources->map(fn (AssignmentSource $s) => [
                'id' => $s->id,
                'sourceable_type' => $s->sourceable_type,
                'sourceable_id' => $s->sourceable_id,
                'added_at' => $s->added_at->toISOString(),
            ])->values()->all(),
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
