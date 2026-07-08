<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One org-channel signal for a bulk completion run (F8: one training × many
 * users), replacing the storm of per-completion CompletionCreated events the
 * single-record path emits. Mirrors TrainingAssignmentsBulkChanged: it carries
 * no row payload — peer tabs can't cheaply upsert an unbounded server-paged set
 * — so listeners just re-pull their current page (the completions store bumps
 * its revision and the open Index refetches).
 */
class CompletionsBulkChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker (O3). */
    public readonly ?string $originTab;

    public function __construct(
        public readonly string $orgId,
        public readonly ?string $actorId = null,
    ) {
        $this->originTab = RealtimeOrigin::tab();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    public function broadcastWith(): array
    {
        return [
            'org_id' => $this->orgId,
            'origin_tab' => $this->originTab,
        ];
    }
}
