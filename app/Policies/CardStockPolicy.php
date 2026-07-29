<?php

namespace App\Policies;

use App\Models\CardStock;
use App\Models\User;

/**
 * Card-stock gates, mirroring MergeFieldPolicy: listing is Manager+ (they
 * pick a stock when printing cards), defining is Admin+ and only for the
 * org's own stocks — system stocks (org_id NULL) are read-only from the org
 * UI, managed via console/seeder until site-admin tooling exists.
 */
class CardStockPolicy
{
    private const USE_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const DEFINE_ROLES = ['Owner', 'SuperAdmin', 'Admin'];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyRole(self::USE_ROLES);
    }

    public function view(User $actor, CardStock $stock): bool
    {
        return $actor->hasAnyRole(self::USE_ROLES)
            && ($stock->org_id === null || $stock->org_id === $actor->org_id);
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function update(User $actor, CardStock $stock): bool
    {
        return $stock->org_id !== null
            && $actor->org_id === $stock->org_id
            && $actor->hasAnyRole(self::DEFINE_ROLES);
    }

    public function delete(User $actor, CardStock $stock): bool
    {
        return $this->update($actor, $stock);
    }
}
