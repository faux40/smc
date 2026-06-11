<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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

    // ------------------------------------------------------------------
    // Org-level rollups — weekly digest (J4) + dashboard widgets (K3/K4).
    // ------------------------------------------------------------------

    /**
     * Headline counts for an org: TAs per bucket plus user coverage.
     *
     * @return array{counts: array<string, int>, total_assignments: int, total_users: int, users_with_overdue: int}
     */
    public function orgSummary(Organization $org): array
    {
        $window = $org->expiringSoonDays();
        $counts = array_fill_keys(self::STATUSES, 0);
        $usersWithOverdue = [];

        $tas = $this->orgAssignments($org);

        foreach ($tas as $ta) {
            $bucket = $this->statusFor($ta, $window);
            $counts[$bucket]++;

            if ($bucket === self::STATUS_OVERDUE) {
                $usersWithOverdue[$ta->user_id] = true;
            }
        }

        return [
            'counts' => $counts,
            'total_assignments' => $tas->count(),
            'total_users' => User::where('org_id', $org->id)->count(),
            'users_with_overdue' => count($usersWithOverdue),
        ];
    }

    /**
     * Users ranked by overdue-training count, worst first.
     *
     * @return array<int, array{user_id: string, name: string|null, overdue_count: int}>
     */
    public function topOverdueUsers(Organization $org, int $limit): array
    {
        $window = $org->expiringSoonDays();

        return $this->orgAssignments($org)
            ->filter(fn (TrainingAssignment $ta) => $this->statusFor($ta, $window) === self::STATUS_OVERDUE)
            ->groupBy('user_id')
            ->map(fn (Collection $group) => [
                'user_id' => $group->first()->user_id,
                'name' => $group->first()->user?->name,
                'overdue_count' => $group->count(),
            ])
            ->sortByDesc('overdue_count')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Soonest-expiring due-soon trainings across the org.
     *
     * @return array<int, array{user_id: string, user_name: string|null, training_name: string, next_due_date: string|null, days_until_due: int|null}>
     */
    public function topDueSoon(Organization $org, int $limit): array
    {
        $window = $org->expiringSoonDays();

        return $this->orgAssignments($org)
            ->filter(fn (TrainingAssignment $ta) => $this->statusFor($ta, $window) === self::STATUS_DUE_SOON)
            ->sortBy('expires_at')
            ->take($limit)
            ->map(fn (TrainingAssignment $ta) => [
                'user_id' => $ta->user_id,
                'user_name' => $ta->user?->name,
                'training_name' => $ta->name,
                'next_due_date' => $ta->expires_at?->toDateString(),
                'days_until_due' => $this->daysUntilDue($ta),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TrainingAssignment>
     */
    private function orgAssignments(Organization $org): Collection
    {
        return TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->with('user')
            ->get();
    }
}
