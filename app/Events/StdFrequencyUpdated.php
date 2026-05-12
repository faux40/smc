<?php

namespace App\Events;

use App\Models\StdFrequency;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StdFrequencyUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly StdFrequency $frequency)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->frequency->org_id)];
    }

    /** @return array{id: string, name: string, repeat_days: int, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->frequency->id,
            'name' => $this->frequency->name,
            'repeat_days' => $this->frequency->repeat_days,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
