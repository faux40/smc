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

    /**
     * Re-running a failed generation carries the same authority as viewing
     * it — Manager+, same org, and generation is already Manager+ via
     * create(). Kept as its own method so the two can diverge later without
     * hunting through call sites.
     */
    public function retry(User $actor, GeneratedDocument $doc): bool
    {
        return $this->view($actor, $doc);
    }
}
