<?php

namespace App\Policies;

use App\Models\Completion;
use App\Models\User;

/**
 * Completions record that a user satisfied one or more rqmt_elements.
 * Phase 10 ships admin-only CRUD; self-view + self-create show up in
 * Phase 12.3 once the user-facing pages exist.
 */
class CompletionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function view(User $actor, Completion $completion): bool
    {
        return $actor->org_id === $completion->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, Completion $completion): bool
    {
        return $actor->org_id === $completion->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, Completion $completion): bool
    {
        return $this->update($actor, $completion);
    }
}
