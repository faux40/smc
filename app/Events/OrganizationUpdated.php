<?php

namespace App\Events;

use App\Models\Organization;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrganizationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Organization $organization) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->organization->id)];
    }

    /**
     * @return array{id: string, name: string, origin_tab: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->organization->id,
            'name' => $this->organization->name,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
