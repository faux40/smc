<?php

namespace App\Events;

use App\Models\Training;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly Training $training)
    {
        $this->originTab = RealtimeOrigin::tab();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->training->org_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->training->id,
            'name' => $this->training->name,
            'description' => $this->training->description,
            'initial_only' => $this->training->initial_only,
            'repeating' => $this->training->repeating,
            'std_freq_id' => $this->training->std_freq_id,
            'as_needed' => $this->training->as_needed,
            'origin_tab' => $this->originTab,
        ];
    }
}
