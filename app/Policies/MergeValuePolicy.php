<?php

namespace App\Policies;

use App\Models\MergeValue;
use App\Models\User;

/**
 * Merge-value (org document data) gates: Manager+ throughout — data
 * entry is day-to-day manager work (decision 2026-07-11), unlike field
 * *definitions* which are Admin+ (see MergeFieldPolicy).
 */
class MergeValuePolicy
{
    private const MANAGE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $actor, MergeValue $value): bool
    {
        return $actor->org_id === $value->org_id
            && $actor->hasAnyRole(self::MANAGE_ROLES);
    }
}
