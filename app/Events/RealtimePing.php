<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Permanent smoke event for the realtime substrate.
 *
 * Broadcast on the public `realtime-ping` channel so devs can confirm
 * end-to-end wiring (server → Reverb → Echo → frontend handler) without
 * needing auth setup. Kept as a breakage canary across phases.
 */
class RealtimePing implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $message,
        public readonly ?string $originTab = null,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('realtime-ping')];
    }

    /**
     * @return array{message: string, origin_tab: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'origin_tab' => $this->originTab ?? RealtimeOrigin::tab(),
        ];
    }
}
