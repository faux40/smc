<?php

namespace App\Policies;

use App\Models\GeneratedDocument;
use App\Models\User;

/**
 * Generated-document gates: Manager+ throughout — generating and
 * managing outputs is day-to-day manager work (same tier as merge-value
 * entry, decision 2026-07-11).
 */
class GeneratedDocumentPolicy
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

    public function view(User $actor, GeneratedDocument $doc): bool
    {
        return $actor->org_id === $doc->org_id
            && $actor->hasAnyRole(self::MANAGE_ROLES);
    }

    public function delete(User $actor, GeneratedDocument $doc): bool
    {
        return $this->view($actor, $doc);
    }
}
