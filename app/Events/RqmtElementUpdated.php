<?php

namespace App\Events;

use App\Models\RqmtElement;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RqmtElementUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly RqmtElement $element)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->element->org_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->element->id,
            'requirement_id' => $this->element->requirement_id,
            'name' => $this->element->name,
            'description' => $this->element->description,
            'initial_only' => $this->element->initial_only,
            'repeating' => $this->element->repeating,
            'std_freq_id' => $this->element->std_freq_id,
            'as_needed' => $this->element->as_needed,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
