<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Watchdog bookkeeping (Phase 15.3). One row per assignment holding the
 * status the daily scan last saw, so `assignments:scan-due-states` can
 * detect edge transitions into `due_soon` / `overdue` instead of
 * re-firing every run.
 */
class AssignmentNotificationState extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'org_id',
        'assignment_id',
        'last_seen_status',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }
}
