<?php

namespace App\Listeners;

use App\Events\AssignmentCreated;
use App\Models\User;
use App\Notifications\AssignmentCreatedForYou;

/**
 * Dispatches the per-user inbox notification when an assignment is
 * created.
 *
 * Suppression rules (per the Phase 15 catalog):
 *   1. Self-action: the actor and the recipient are the same user — no
 *      notification (the actor just clicked Save; pinging them is
 *      noise).
 *   2. Bulk-fanout: events fired from BulkAssignmentsController carry
 *      fromBulk=true so a 50-pair batch doesn't generate 50 inbox
 *      entries per user. A future polish could coalesce into a single
 *      "you got N new assignments" digest.
 */
class NotifyAssignmentCreated
{
    public function handle(AssignmentCreated $event): void
    {
        if ($event->fromBulk) {
            return;
        }

        $recipient = User::query()
            ->withoutGlobalScope('organization')
            ->find($event->assignment->user_id);
        if ($recipient === null) {
            return;
        }

        if ($event->actorId !== null && $event->actorId === $recipient->id) {
            return;
        }

        $recipient->notify(new AssignmentCreatedForYou($event->assignment));
    }
}
