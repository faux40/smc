<?php

namespace App\Policies;

use App\Models\DocTemplate;
use App\Models\User;

/**
 * Doc-template gates (decision 2026-07-11): listing/generating is
 * Manager+; template upload/replace/rename/delete is Admin+ and only
 * for the org's own templates — system templates are read-only from
 * the org UI, console-managed until site-admin tooling exists.
 */
class DocTemplatePolicy
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

    public function update(User $actor, DocTemplate $template): bool
    {
        return $template->org_id !== null
            && $actor->org_id === $template->org_id
            && $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function delete(User $actor, DocTemplate $template): bool
    {
        return $this->update($actor, $template);
    }
}
