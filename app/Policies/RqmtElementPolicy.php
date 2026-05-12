<?php

namespace App\Policies;

use App\Models\RqmtElement;
use App\Models\User;

/**
 * rqmt_elements live inside a Requirement. Same role/org gating as the
 * Requirements policy — viewAny any auth'd user (for downstream pickers),
 * CRUD admin-only.
 */
class RqmtElementPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, RqmtElement $element): bool
    {
        return $actor->org_id === $element->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, RqmtElement $element): bool
    {
        return $this->update($actor, $element);
    }
}
