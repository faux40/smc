<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingAssignmentDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $trainingAssignmentId,
        public readonly string $userId,
        public readonly string $trainingId,
        public readonly string $orgId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->trainingAssignmentId,
            'user_id' => $this->userId,
            'training_id' => $this->trainingId,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
