<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A class aggregate changed — created/updated/deleted, or a training/enrollment
 * added or removed. One event for the whole aggregate: peers re-sync the
 * affected class (and the list) rather than patching granular sub-resources.
 * `action` is advisory for the client.
 */
class ClassChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $classId,
        public readonly string $orgId,
        public readonly string $action,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'class_id' => $this->classId,
            'action' => $this->action,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
