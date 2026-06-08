<?php

namespace App\Policies;

use App\Models\TrainingAssignment;
use App\Models\User;

/**
 * Training assignments are managed by Manager+; deletion is Owner/SA/Admin
 * only (mirrors AssignmentPolicy roles — Managers can assign but not remove).
 */
class TrainingAssignmentPolicy
{
    private const WRITE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    private const READ_AND_CREATE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function view(User $actor, TrainingAssignment $ta): bool
    {
        return $actor->org_id === $ta->org_id
            && $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::READ_AND_CREATE_ROLES);
    }

    public function delete(User $actor, TrainingAssignment $ta): bool
    {
        return $actor->org_id === $ta->org_id
            && $actor->hasAnyRole(self::WRITE_ROLES);
    }

    public function deleteAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::WRITE_ROLES);
    }
}
