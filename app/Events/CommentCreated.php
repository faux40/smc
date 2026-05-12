<?php

namespace App\Events;

use App\Models\Comment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Comment $comment)
    {
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->comment->org_id)];
    }

    /** @return array{id: string, commentable_type: string, commentable_id: string, author_id: string, parent_id: ?string, body: string, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->id,
            'commentable_type' => $this->comment->commentable_type,
            'commentable_id' => $this->comment->commentable_id,
            'author_id' => $this->comment->author_id,
            'parent_id' => $this->comment->parent_id,
            'body' => $this->comment->body,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
