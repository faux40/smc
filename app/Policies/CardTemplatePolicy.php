<?php

namespace App\Policies;

use App\Models\CardTemplate;
use App\Models\User;

/**
 * Card-template gates, matching DocTemplatePolicy: listing (and printing
 * from) is Manager+; upload/replace/rename/delete is Admin+ and only for the
 * org's own templates — system templates are read-only from the org UI.
 */
class CardTemplatePolicy
{
    private const USE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const DEFINE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::USE_ROLES);
    }

    public function view(User $actor, CardTemplate $template): bool
    {
        return $actor->hasAnyRole(self::USE_ROLES)
            && ($template->org_id === null || $template->org_id === $actor->org_id);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function update(User $actor, CardTemplate $template): bool
    {
        return $template->org_id !== null
            && $actor->org_id === $template->org_id
            && $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function delete(User $actor, CardTemplate $template): bool
    {
        return $this->update($actor, $template);
    }
}
