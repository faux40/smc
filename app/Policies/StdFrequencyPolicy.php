<?php

namespace App\Policies;

use App\Models\StdFrequency;
use App\Models\User;

/**
 * Std frequencies are per-org timing presets used by downstream forms
 * (Trainings, RqmtElements, Assignments). Anyone in the org needs to
 * list them (the picker is everywhere); only admins can manage the library.
 */
class StdFrequencyPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, StdFrequency $freq): bool
    {
        return $actor->org_id === $freq->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, StdFrequency $freq): bool
    {
        return $this->update($actor, $freq);
    }
}
