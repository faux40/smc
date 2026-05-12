<?php

namespace App\Policies;

use App\Models\Training;
use App\Models\User;

/**
 * Trainings are the first concrete module. Org-wide library: anyone can
 * see the list (downstream rqmt_elements pickers need it), but only
 * admins manage.
 */
class TrainingPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function update(User $actor, Training $training): bool
    {
        return $actor->org_id === $training->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin']);
    }

    public function delete(User $actor, Training $training): bool
    {
        return $this->update($actor, $training);
    }
}
