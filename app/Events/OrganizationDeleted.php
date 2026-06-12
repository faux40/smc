<?php

namespace App\Events;

use App\Models\Organization;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrganizationDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly Organization $organization)
    {
        $this->originTab = RealtimeOrigin::tab();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->organization->id)];
    }

    /**
     * @return array{id: string, origin_tab: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->organization->id,
            'origin_tab' => $this->originTab,
        ];
    }
}
