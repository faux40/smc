<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/**
 * Tag library role gates.
 *
 * Listing / using tags is open to any auth'd org member (the controller
 * scopes the query to the actor's org). Library CRUD (create / rename /
 * recolor / delete) is Owner / SuperAdmin / Admin. Attach / detach is
 * NOT gated through this policy — the controller does same-org checks
 * inline since it's a low-friction descriptive operation, not
 * access-control.
 */
class TagPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, Tag $tag): bool
    {
        return $actor->org_id === $tag->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, Tag $tag): bool
    {
        return $this->update($actor, $tag);
    }
}
