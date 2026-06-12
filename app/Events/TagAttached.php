<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TagAttached implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(
        public readonly string $orgId,
        public readonly string $tagId,
        public readonly string $taggableType,
        public readonly string $taggableId,
    ) {
        $this->originTab = RealtimeOrigin::tab();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    /** @return array{tag_id: string, taggable_type: string, taggable_id: string, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'tag_id' => $this->tagId,
            'taggable_type' => $this->taggableType,
            'taggable_id' => $this->taggableId,
            'origin_tab' => $this->originTab,
        ];
    }
}
