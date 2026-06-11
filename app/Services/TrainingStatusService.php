<?php

namespace App\Services;

use App\Models\TrainingAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * J3 — the single place training-assignment status is computed. Buckets are
 * mutually exclusive and complete: every TA lands in exactly one. Pure
 * function of the TA's flattened columns (expires_at / last_completed_at /
 * as_needed_only, maintained by RecalculateTrainingStatus) plus the org's
 * amber window, so every serializer — TA index, dashboard, user compliance —
 * agrees by construction.
 */
class TrainingStatusService
{
    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_CURRENT = 'current';

    public const STATUS_AS_NEEDED = 'as_needed';

    /** Worst-first display order. */
    public const STATUSES = [
        self::STATUS_OVERDUE,
        self::STATUS_DUE_SOON,
        self::STATUS_NOT_STARTED,
        self::STATUS_CURRENT,
        self::STATUS_AS_NEEDED,
    ];

    /**
     * @param  int  $dueSoonDays  the org's amber window (Organization::expiringSoonDays())
     */
    public function statusFor(
        TrainingAssignment $ta,
        int $dueSoonDays,
        ?CarbonInterface $today = null,
    ): string {
        $today = ($today ?? Date::now())->startOfDay();

        // Visible but never scheduled or required — regardless of completion.
        if ($ta->as_needed_only) {
            return self::STATUS_AS_NEEDED;
        }

        if ($ta->last_completed_at === null) {
            return self::STATUS_NOT_STARTED;
        }

        if ($ta->expires_at === null) {
            return self::STATUS_CURRENT;
        }

        if ($ta->expires_at->lt($today)) {
            return self::STATUS_OVERDUE;
        }

        if ($ta->expires_at->lte($today->addDays($dueSoonDays))) {
            return self::STATUS_DUE_SOON;
        }

        return self::STATUS_CURRENT;
    }

    /**
     * Signed days until expiry: negative when past due, null when the TA has
     * no computed expiry.
     */
    public function daysUntilDue(TrainingAssignment $ta, ?CarbonInterface $today = null): ?int
    {
        if ($ta->expires_at === null) {
            return null;
        }

        $today = ($today ?? Date::now())->startOfDay();

        return (int) $today->diffInDays($ta->expires_at->startOfDay(), false);
    }
}
