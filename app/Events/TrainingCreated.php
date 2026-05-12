<?php

namespace App\Events;

use App\Models\Training;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Training $training)
    {
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
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
