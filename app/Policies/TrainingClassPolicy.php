<?php

namespace App\Policies;

use App\Models\TrainingClass;
use App\Models\User;

/**
 * Classes are a Manager+ scheduling tool (mirrors the assignment/dashboard
 * widening). Every action is same-org + Manager-or-higher.
 */
class TrainingClassPolicy
{
    private const MANAGE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function view(User $actor, TrainingClass $class): bool
    {
        return $actor->org_id === $class->org_id && $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function update(User $actor, TrainingClass $class): bool
    {
        return $actor->org_id === $class->org_id && $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $actor, TrainingClass $class): bool
    {
        return $this->update($actor, $class);
    }
}
