<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    private const ADMIN_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::ADMIN_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::ADMIN_ROLES);
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->org_id !== $target->org_id) {
            return false;
        }

        if (! $actor->hasAnyRole(self::ADMIN_ROLES)) {
            return false;
        }

        // Owners can only be modified by the Owner themselves.
        if ($target->hasRole('Owner')) {
            return $actor->id === $target->id;
        }

        return true;
    }

    public function disable(User $actor, User $target): bool
    {
        // Owner-protected + can't disable self via admin route.
        return $this->update($actor, $target)
            && ! $target->hasRole('Owner')
            && $actor->id !== $target->id;
    }

    public function enable(User $actor, User $target): bool
    {
        return $this->disable($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        // Same gates as disable: Owner-protected + can't self-delete via admin route.
        return $this->disable($actor, $target);
    }
}
