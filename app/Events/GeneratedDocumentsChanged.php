<?php

namespace App\Events;

use App\Support\RealtimeOrigin;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Coarse "the merge-field library changed" signal (created / updated /
 * deleted) — peer tabs bump a store revision and refetch, mirroring the
 * TrainingAssignmentsBulkChanged pattern rather than per-row payloads.
 */
class GeneratedDocumentsChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Captured at construct — the X-Origin-Tab header doesn't exist in the queue worker. */
    public readonly ?string $originTab;

    public function __construct(public readonly string $orgId)
    {
        $this->originTab = RealtimeOrigin::tab();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('org.'.$this->orgId)];
    }

    /** @return array{origin_tab: ?string} */
    public function broadcastWith(): array
    {
        return ['origin_tab' => $this->originTab];
    }
}
