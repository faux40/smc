<?php

namespace App\Events;

use App\Models\User;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly User $user) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->user->org_id)];
    }

    /**
     * @return array{id: string, name: string, email: ?string, status: string, role: ?string, origin_tab: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'status' => $this->user->status,
            'role' => $this->user->roles->first()?->name,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
