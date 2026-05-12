<?php

namespace App\Listeners;

use App\Events\CompletionCreated;
use App\Models\User;
use App\Notifications\CompletionRecordedForYou;

/**
 * Dispatches the per-user inbox notification when a completion is
 * recorded.
 *
 * Suppression: self-action (actor == recipient) — the actor just
 * filled out the form, no point pinging them. There's no bulk-flow
 * for completions today; if one lands later it should add a fromBulk
 * flag to CompletionCreated and this listener should respect it.
 */
class NotifyCompletionRecorded
{
    public function handle(CompletionCreated $event): void
    {
        $recipient = User::query()
            ->withoutGlobalScope('organization')
            ->find($event->completion->user_id);
        if ($recipient === null) {
            return;
        }

        if ($event->actorId !== null && $event->actorId === $recipient->id) {
            return;
        }

        // Eager-load the pivot so the notification payload's
        // `rqmt_element_ids` array doesn't N+1 on every dispatch.
        $event->completion->loadMissing('rqmtElements:id');

        $recipient->notify(new CompletionRecordedForYou($event->completion));
    }
}
