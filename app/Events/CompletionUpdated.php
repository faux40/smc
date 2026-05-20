<?php

namespace App\Events;

use App\Models\Completion;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CompletionUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Completion $completion) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->completion->org_id)];
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->completion->id,
            'user_id' => $this->completion->user_id,
            'module_type' => $this->completion->module_type,
            'module_id' => $this->completion->module_id,
            'completion_date' => optional($this->completion->completion_date)->toDateString(),
            'certification_date' => optional($this->completion->certification_date)->toDateString(),
            'expire_date' => optional($this->completion->expire_date)->toDateString(),
            'cert_ident' => $this->completion->cert_ident,
            'notes' => $this->completion->notes,
            'rqmt_element_ids' => $this->completion->rqmtElements()->pluck('rqmt_elements.id')->all(),
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
