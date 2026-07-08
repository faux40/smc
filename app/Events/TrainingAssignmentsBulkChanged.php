<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One org-channel signal for a bulk assignment run, replacing the storm of
 * per-TA TrainingAssignmentCreated events the bulk path used to emit (a
 * 5-element requirement to 500 users was 2,500 broadcasts). Peer tabs can't
 * cheaply upsert an unbounded set of rows they may not even have paged in, so
 * this carries no row payload — listeners just re-pull their current page
 * (useServerTable.refetchSoon), the same debounced refetch the single-assign
 * event already triggers via the store's revision bump.
 */
class TrainingAssignmentsBulkChanged implements ShouldBroadcast
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
