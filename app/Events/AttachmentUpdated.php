<?php

namespace App\Events;

use App\Models\Attachment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttachmentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(public readonly Attachment $attachment)
    {
        $this->originTab = RealtimeOrigin::tab();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->attachment->org_id)];
    }

    /** @return array{id: string, type: ?string, description: ?string, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->attachment->id,
            'type' => $this->attachment->type,
            'description' => $this->attachment->description,
            'origin_tab' => $this->originTab,
        ];
    }
}
