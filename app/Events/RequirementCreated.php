<?php

namespace App\Events;

use App\Models\Requirement;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequirementCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Requirement $requirement) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->requirement->org_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->requirement->id,
            'name' => $this->requirement->name,
            'description' => $this->requirement->description,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
