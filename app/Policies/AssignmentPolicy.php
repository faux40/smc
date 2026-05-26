<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;

/**
 * Assignments tie a user to a requirement on a per-(user, requirement)
 * timing record.
 *
 * Phase 13.1 widened viewAny / view / create to include Manager so the
 * tag-driven bulk-assignment workflow has a sensible role gate (the
 * outline describes the flow as a manager-led one). Update + delete
 * stay Owner/SA/Admin — re-targeting or removing existing assignments
 * is a heavier audit-trail operation. Self-view lands in 12.3.
 */
class AssignmentPolicy
{
    private const WRITE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    private const READ_AND_CREATE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function view(User $actor, Assignment $assignment): bool
    {
        return $actor->org_id === $assignment->org_id
            && $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function update(User $actor, Assignment $assignment): bool
    {
        return $actor->org_id === $assignment->org_id
            && $actor->hasAnyRole(self::WRITE_ROLES);
    }

    public function delete(User $actor, Assignment $assignment): bool
    {
        return $this->update($actor, $assignment);
    }

    /**
     * Class-level gate for the bulk de-assign endpoint (no single instance
     * to check). Same write roles as delete; per-row org scoping is enforced
     * by the org-scoped query in the controller.
     */
    public function deleteAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::WRITE_ROLES);
    }
}
