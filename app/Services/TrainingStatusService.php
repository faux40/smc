<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\User;
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
     * Human labels for each bucket — the canonical vocabulary shared by the
     * compliance UI badge (ComplianceStatusBadge.vue) and the server-rendered
     * compliance-status report (so an exported PDF/CSV reads the same words the
     * screen shows).
     */
    public const LABELS = [
        self::STATUS_OVERDUE => 'Overdue',
        self::STATUS_DUE_SOON => 'Due soon',
        self::STATUS_NOT_STARTED => 'Not started',
        self::STATUS_CURRENT => 'Current',
        self::STATUS_AS_NEEDED => 'As needed',
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
     * Headline counts for an org: TAs per bucket plus user coverage. One
     * GROUP-less aggregate over the materialized status (indexed on
     * (org_id, status)) — no per-assignment hydration.
     *
     * @return array{counts: array<string, int>, total_assignments: int, total_users: int, users_with_overdue: int}
     */
    public function orgSummary(Organization $org): array
    {
        $row = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->selectRaw(<<<'SQL'
                COUNT(*) as total,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'due_soon' THEN 1 ELSE 0 END) as due_soon,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status = 'current' THEN 1 ELSE 0 END) as current,
                SUM(CASE WHEN status = 'as_needed' THEN 1 ELSE 0 END) as as_needed,
                COUNT(DISTINCT CASE WHEN status = 'overdue' THEN user_id END) as users_with_overdue
            SQL)
            ->first();

        return [
            'counts' => [
                self::STATUS_OVERDUE => (int) ($row->overdue ?? 0),
                self::STATUS_DUE_SOON => (int) ($row->due_soon ?? 0),
                self::STATUS_NOT_STARTED => (int) ($row->not_started ?? 0),
                self::STATUS_CURRENT => (int) ($row->current ?? 0),
                self::STATUS_AS_NEEDED => (int) ($row->as_needed ?? 0),
            ],
            'total_assignments' => (int) ($row->total ?? 0),
            'total_users' => User::where('org_id', $org->id)->count(),
            'users_with_overdue' => (int) ($row->users_with_overdue ?? 0),
        ];
    }

    /**
     * Users ranked by overdue-training count, worst first. Grouped in SQL over
     * the materialized status; names hydrated for the page only.
     *
     * @return array<int, array{user_id: string, name: string|null, overdue_count: int}>
     */
    public function topOverdueUsers(Organization $org, int $limit): array
    {
        $rows = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->where('status', self::STATUS_OVERDUE)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as overdue_count')
            ->orderByDesc('overdue_count')
            ->limit($limit)
            ->get();

        $names = User::whereIn('id', $rows->pluck('user_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(fn ($r) => [
            'user_id' => $r->user_id,
            'name' => $names->get($r->user_id)?->sort_name,
            'overdue_count' => (int) $r->overdue_count,
        ])->all();
    }

    /**
     * Soonest-expiring due-soon trainings across the org. Filtered on the
     * materialized status and ordered by expiry in SQL.
     *
     * @return array<int, array{user_id: string, user_name: string|null, training_name: string, next_due_date: string|null, days_until_due: int|null}>
     */
    public function topDueSoon(Organization $org, int $limit): array
    {
        $tas = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->where('status', self::STATUS_DUE_SOON)
            ->orderByRaw('expires_at IS NULL') // non-null expiries first
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $names = User::whereIn('id', $tas->pluck('user_id'))
            ->get()
            ->keyBy('id');

        return $tas->map(fn (TrainingAssignment $ta) => [
            'user_id' => $ta->user_id,
            'user_name' => $names->get($ta->user_id)?->sort_name,
            'training_name' => $ta->name,
            'next_due_date' => $ta->expires_at?->toDateString(),
            'days_until_due' => $this->daysUntilDue($ta),
        ])->all();
    }

    /** Sort keys the all-users compliance table exposes. */
    private const USERS_SORTABLE = ['name', 'status', 'overdue', 'due_soon'];

    /**
     * One row per org user: per-bucket counts, worst non-empty bucket as
     * overall_status ('none' when the user has no TAs), and tag ids for the
     * dashboard's tag chips. Feeds the all-users compliance table (K3).
     *
     * Search / sort / pagination all run in SQL: users left-join a per-user
     * grouped subquery over the materialized status, so only the requested
     * page is hydrated. Returns the paginated {data, meta} envelope.
     *
     * @param  array<string, mixed>  $opts  q, sort, dir, page, per_page
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function usersComplianceSummary(Organization $org, array $opts = []): array
    {
        $counts = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->groupBy('user_id')
            ->selectRaw(<<<'SQL'
                user_id,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'due_soon' THEN 1 ELSE 0 END) as due_soon,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status = 'current' THEN 1 ELSE 0 END) as current_count,
                SUM(CASE WHEN status = 'as_needed' THEN 1 ELSE 0 END) as as_needed_count
            SQL);

        $query = User::query()
            ->where('users.org_id', $org->id)
            ->leftJoinSub($counts, 'tac', 'tac.user_id', '=', 'users.id')
            ->select('users.*')
            ->selectRaw('COALESCE(tac.overdue, 0) as c_overdue')
            ->selectRaw('COALESCE(tac.due_soon, 0) as c_due_soon')
            ->selectRaw('COALESCE(tac.not_started, 0) as c_not_started')
            ->selectRaw('COALESCE(tac.current_count, 0) as c_current')
            ->selectRaw('COALESCE(tac.as_needed_count, 0) as c_as_needed');

        if ($like = $this->searchLike($opts)) {
            $query->where(function ($w) use ($like) {
                foreach (['f_name', 'm_name', 'l_name', 'email'] as $col) {
                    $w->orWhereRaw("LOWER(users.{$col}) LIKE ?", [$like]);
                }
            });
        }

        // Worst-first status precedence mirrors overallStatus()/STATUSES.
        $statusRank = <<<'SQL'
            CASE
                WHEN COALESCE(tac.overdue, 0) > 0 THEN 0
                WHEN COALESCE(tac.due_soon, 0) > 0 THEN 1
                WHEN COALESCE(tac.not_started, 0) > 0 THEN 2
                WHEN COALESCE(tac.current_count, 0) > 0 THEN 3
                WHEN COALESCE(tac.as_needed_count, 0) > 0 THEN 4
                ELSE 99
            END
        SQL;

        $sort = in_array($opts['sort'] ?? null, self::USERS_SORTABLE, true) ? $opts['sort'] : 'overdue';
        $dir = ($opts['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'name' => $query->orderByRaw("LOWER(users.l_name) {$dir}")->orderByRaw("LOWER(users.f_name) {$dir}"),
            'status' => $query->orderByRaw("({$statusRank}) {$dir}"),
            'due_soon' => $query->orderByRaw("COALESCE(tac.due_soon, 0) {$dir}"),
            default => $query->orderByRaw("COALESCE(tac.overdue, 0) {$dir}"),
        };

        // Stable, page-consistent tiebreak.
        $query->orderBy('users.l_name')->orderBy('users.f_name')->orderBy('users.id');

        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->load('tags:id');

        return [
            'data' => $paginator->getCollection()->map(function (User $user) {
                $counts = [
                    self::STATUS_OVERDUE => (int) $user->c_overdue,
                    self::STATUS_DUE_SOON => (int) $user->c_due_soon,
                    self::STATUS_NOT_STARTED => (int) $user->c_not_started,
                    self::STATUS_CURRENT => (int) $user->c_current,
                    self::STATUS_AS_NEEDED => (int) $user->c_as_needed,
                ];

                return [
                    'user_id' => $user->id,
                    'name' => $user->sort_name,
                    'email' => $user->email,
                    'counts' => $counts,
                    'overall_status' => $this->overallStatus($counts),
                    'tag_ids' => $user->tags->pluck('id')->all(),
                ];
            })->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** Normalized LIKE term, or null when no search. */
    private function searchLike(array $opts): ?string
    {
        $q = trim((string) ($opts['q'] ?? ''));

        return $q === '' ? null : '%'.mb_strtolower($q).'%';
    }

    /**
     * Worst non-empty bucket by STATUSES precedence (overdue first); 'none'
     * when the user has no assignments at all.
     *
     * @param  array<string, int>  $counts
     */
    private function overallStatus(array $counts): string
    {
        foreach (self::STATUSES as $bucket) {
            if (($counts[$bucket] ?? 0) > 0) {
                return $bucket;
            }
        }

        return 'none';
    }
}
