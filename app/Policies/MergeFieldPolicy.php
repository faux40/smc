<?php

namespace App\Policies;

use App\Models\MergeField;
use App\Models\User;

/**
 * Merge-field definition gates (decision 2026-07-11): listing is
 * Manager+ (they enter the data), definition CRUD is Admin+ and only
 * for the org's own fields — system fields (org_id NULL) are read-only
 * from the org UI, managed via console/seeder until site-admin tooling
 * exists.
 */
class MergeFieldPolicy
{
    private const MANAGE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const DEFINE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function update(User $actor, MergeField $field): bool
    {
        return $field->org_id !== null
            && $actor->org_id === $field->org_id
            && $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function delete(User $actor, MergeField $field): bool
    {
        return $this->update($actor, $field);
    }
}
