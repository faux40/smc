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

    /**
     * Reading one training's detail — used by the card-field definitions,
     * which a Manager needs to print cards even though defining them is
     * Admin+. Deliberately narrower than viewAny(): that one is open so
     * pickers can list trainings by name.
     */
    public function view(User $actor, Training $training): bool
    {
        return $actor->org_id === $training->org_id
            && $actor->hasAnyRole(['Owner', 'SuperAdmin', 'Admin', 'Manager']);
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
