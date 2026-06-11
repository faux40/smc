<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Watchdog bookkeeping (Phase 15.3, repointed to the TA engine in J4).
 * One row per training assignment holding the status the daily scan last
 * saw, so `assignments:scan-due-states` can detect edge transitions into
 * `due_soon` / `overdue` instead of re-firing every run.
 */
class AssignmentNotificationState extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'org_id',
        'training_assignment_id',
        'last_seen_status',
    ];

    public function trainingAssignment(): BelongsTo
    {
        return $this->belongsTo(TrainingAssignment::class, 'training_assignment_id');
    }
}
