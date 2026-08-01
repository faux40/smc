<?php

namespace App\Policies;

use App\Models\CardFont;
use App\Models\User;

/**
 * Uploaded-font gates, mirroring CardStockPolicy: seeing the library is
 * Manager+ (they print, and need to know why a substitution warning cleared
 * or didn't), adding and removing is Admin+ and only within the actor's own
 * org — fonts are licensed per org, so they never cross one.
 */
class CardFontPolicy
{
    private const USE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const DEFINE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::USE_ROLES);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function delete(User $actor, CardFont $font): bool
    {
        return $actor->org_id === $font->org_id
            && $actor->hasAnyRole(self::DEFINE_ROLES);
    }
}
