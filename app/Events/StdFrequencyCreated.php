<?php

namespace App\Events;

use App\Models\StdFrequency;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StdFrequencyCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly StdFrequency $frequency)
    {
        $this->originTab = RealtimeOrigin::tab();
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
            'origin_tab' => $this->originTab,
        ];
    }
}
