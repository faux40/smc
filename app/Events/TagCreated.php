<?php

namespace App\Events;

use App\Models\Tag;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TagCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Tag $tag)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->tag->org_id)];
    }

    /** @return array{id: string, name: string, color: ?string, font_color: ?string, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->tag->id,
            'name' => $this->tag->name,
            'color' => $this->tag->color,
            'font_color' => $this->tag->font_color,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
