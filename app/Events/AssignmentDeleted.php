<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssignmentDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $assignmentId,
        public readonly string $userId,
        public readonly string $requirementId,
        public readonly string $orgId,
    ) {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->assignmentId,
            'user_id' => $this->userId,
            'requirement_id' => $this->requirementId,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
