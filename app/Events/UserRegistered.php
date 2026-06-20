<?php

namespace App\Events;

use App\Models\User;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserRegistered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly User $user)
    {
        $this->originTab = RealtimeOrigin::tab();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->user->org_id)];
    }

    /**
     * @return array{id: string, name: string, sort_name: string, email: ?string, origin_tab: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'sort_name' => $this->user->sort_name,
            'email' => $this->user->email,
            'origin_tab' => $this->originTab,
        ];
    }
}
