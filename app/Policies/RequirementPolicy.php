<?php

namespace App\Policies;

use App\Models\Requirement;
use App\Models\User;

/**
 * Requirements are the org's compliance library. Anyone needs to see
 * them (downstream assignment pickers + drill-down pages). Library CRUD
 * is admin-only.
 */
class RequirementPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, Requirement $requirement): bool
    {
        return $actor->org_id === $requirement->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, Requirement $requirement): bool
    {
        return $this->update($actor, $requirement);
    }
}
