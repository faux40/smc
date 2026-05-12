<?php

namespace App\Policies;

use App\Models\Completion;
use App\Models\User;

/**
 * Completions record that a user satisfied one or more rqmt_elements.
 *
 * Phase 13.2 widened viewAny / view / create to include Manager so
 * managers who assign requirements can also record their completion
 * (consistent with the AssignmentPolicy widening in 13.1). Update +
 * delete remain Owner/SA/Admin — editing a completion-of-record is
 * a heavier audit-trail operation. Self-create + self-view live in
 * Phase 13.3.
 */
class CompletionPolicy
{
    private const WRITE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];
    private const READ_AND_CREATE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function view(User $actor, Completion $completion): bool
    {
        return $actor->org_id === $completion->org_id
            && $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function update(User $actor, Completion $completion): bool
    {
        return $actor->org_id === $completion->org_id
            && $actor->hasAnyRole(self::WRITE_ROLES);
    }

    public function delete(User $actor, Completion $completion): bool
    {
        return $this->update($actor, $completion);
    }
}
