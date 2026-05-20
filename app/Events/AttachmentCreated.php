<?php

namespace App\Events;

use App\Models\Attachment;
use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttachmentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Attachment $attachment) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->attachment->org_id)];
    }

    /** @return array{id: string, attachable_type: string, attachable_id: string, filename: string, mime: ?string, size: ?int, uploaded_by_user_id: string, origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->attachment->id,
            'attachable_type' => $this->attachment->attachable_type,
            'attachable_id' => $this->attachment->attachable_id,
            'filename' => $this->attachment->filename,
            'mime' => $this->attachment->mime,
            'size' => $this->attachment->size,
            'uploaded_by_user_id' => $this->attachment->uploaded_by_user_id,
            'origin_tab' => RealtimeOrigin::tab(),
        ];
    }
}
