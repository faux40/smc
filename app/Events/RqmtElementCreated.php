<?php

namespace App\Events;

use App\Models\RqmtElement;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RqmtElementCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly RqmtElement $element)
    {
        $this->originTab = RealtimeOrigin::tab();
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
            'module_type' => $this->element->module_type,
            'module_id' => $this->element->module_id,
            // Peer tabs render straight from this payload — same effective-
            // name contract as the index endpoint.
            'name' => $this->element->effectiveName(),
            'custom_name' => $this->element->name,
            'module_name' => $this->element->moduleLiveName(),
            'description' => $this->element->description,
            'initial_only' => $this->element->initial_only,
            'repeating' => $this->element->repeating,
            'std_freq_id' => $this->element->std_freq_id,
            'as_needed' => $this->element->as_needed,
            'origin_tab' => $this->originTab,
        ];
    }
}
