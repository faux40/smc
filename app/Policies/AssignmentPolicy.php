<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

/**
 * Assignments tie a user to a requirement on a per-(user, requirement)
 * timing record. Phase 10 ships admin-only CRUD; self-view lands in 12.3
 * once user-facing dashboards exist.
 */
class AssignmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function view(User $actor, Assignment $assignment): bool
    {
        return $actor->org_id === $assignment->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, Assignment $assignment): bool
    {
        return $actor->org_id === $assignment->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, Assignment $assignment): bool
    {
        return $this->update($actor, $assignment);
    }
}
