<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The single read seam for compliance roll-ups. Reads the denormalized
 * `training_assignments.status` column (maintained in realtime by
 * RecalculateTrainingStatus and reconciled daily by the scan-due-states
 * watchdog) and aggregates with GROUP BY — cheap, indexed, no per-row PHP.
 *
 * Because status is materialized, swapping in a fuller precomputed counts
 * table later would change only this class; the controller + frontend are
 * unaffected.
 */
class ComplianceQuery
{
    public const BUCKETS = ['overdue', 'due_soon', 'not_started', 'current', 'as_needed'];

    /** Sortable columns for the rollup tables (count aliases + name). */
    private const SORTABLE = ['name', 'total', 'overdue', 'due_soon', 'not_started', 'current', 'as_needed'];

    /**
     * Per-training rollup: one row per training that has assignments, with
     * per-bucket counts + total. Paginated {data, meta}.
     *
     * @param  array<string, mixed>  $opts  q, sort, dir, page, per_page
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function byTraining(Organization $org, array $opts = []): array
    {
        $base = DB::table('training_assignments as ta')
            ->join('trainings as t', 't.id', '=', 'ta.training_id')
            ->where('ta.org_id', $org->id)
            ->whereNull('t.deleted_at');

        if ($like = $this->searchLike($opts)) {
            $base->whereRaw('LOWER(t.name) LIKE ?', [$like]);
        }

        return $this->aggregate($base, 't.id', 't.name', $opts);
    }

    /**
     * Per-training rollup of "not required" coverage — trainings people have
     * but weren't required to: the union of (a) direct-only assignments (no
     * active requirement source) and (b) completions of a training the user was
     * never assigned at all. Direct-only rows use the materialized status;
     * orphan completions derive status from the latest completion's own expiry.
     *
     * @param  array<string, mixed>  $opts
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function notRequired(Organization $org, array $opts = []): array
    {
        $today = Date::now()->startOfDay()->toDateString();
        $boundary = Date::now()->startOfDay()->addDays($org->expiringSoonDays())->toDateString();

        // (a) Direct-only assignments → materialized status. training_id is a
        //     uuid while completions.module_id is varchar; Postgres won't union
        //     mismatched types, so both legs cast the id to text (and the join
        //     below matches on text). Mirrors the taggables CAST pattern.
        $direct = DB::table('training_assignments as ta')
            ->where('ta.org_id', $org->id)
            ->whereNotExists(fn ($q) => $q->from('assignment_sources as s')
                ->whereColumn('s.training_assignment_id', 'ta.id')
                ->whereNull('s.removed_at')
                ->where('s.sourceable_type', Requirement::class))
            ->selectRaw('CAST(ta.training_id AS text) as training_id, ta.status as status');

        // (b) Completions of an unassigned training → status from the latest
        //     completion's own expiry (no TA exists to carry a bucket).
        $orphan = DB::table('completions as c')
            ->where('c.org_id', $org->id)
            ->where('c.module_type', Training::class)
            ->whereNotExists(fn ($q) => $q->from('training_assignments as ta2')
                ->whereColumn('ta2.user_id', 'c.user_id')
                // uuid training_id vs varchar module_id — cast to compare.
                ->whereRaw('CAST(ta2.training_id AS text) = c.module_id'))
            ->whereNotExists(fn ($q) => $q->from('completions as c2')
                ->whereColumn('c2.user_id', 'c.user_id')
                ->whereColumn('c2.module_id', 'c.module_id')
                ->where('c2.module_type', Training::class)
                ->whereColumn('c2.completion_date', '>', 'c.completion_date'))
            ->selectRaw(
                "CAST(c.module_id AS text) as training_id, CASE
                    WHEN c.expire_date IS NULL THEN 'current'
                    WHEN c.expire_date < ? THEN 'overdue'
                    WHEN c.expire_date <= ? THEN 'due_soon'
                    ELSE 'current' END as status",
                [$today, $boundary],
            );

        $facts = $direct->unionAll($orphan);

        $base = DB::query()
            ->fromSub($facts, 'f')
            ->join('trainings as t', fn ($join) => $join->whereRaw('CAST(t.id AS text) = f.training_id'))
            ->whereNull('t.deleted_at');

        if ($like = $this->searchLike($opts)) {
            $base->whereRaw('LOWER(t.name) LIKE ?', [$like]);
        }

        return $this->aggregate($base, 't.id', 't.name', $opts, 'f.status');
    }

    /**
     * Per-requirement rollup: counts of the assignments each requirement
     * actively sources (a TA sourced from N requirements counts under each).
     *
     * @param  array<string, mixed>  $opts
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function byRequirement(Organization $org, array $opts = []): array
    {
        $base = DB::table('training_assignments as ta')
            ->join('assignment_sources as s', function ($join) {
                $join->on('s.training_assignment_id', '=', 'ta.id')
                    ->whereNull('s.removed_at')
                    ->where('s.sourceable_type', '=', Requirement::class);
            })
            ->join('requirements as r', 'r.id', '=', 's.sourceable_id')
            ->where('ta.org_id', $org->id)
            ->whereNull('r.deleted_at');

        if ($like = $this->searchLike($opts)) {
            $base->whereRaw('LOWER(r.name) LIKE ?', [$like]);
        }

        return $this->aggregate($base, 'r.id', 'r.name', $opts);
    }

    /**
     * Drill-down: the users assigned a given training, worst-status first.
     * Paginated; small pages since this backs an inline expand panel.
     *
     * @param  array<string, mixed>  $opts  page, per_page
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function usersForTraining(Organization $org, string $trainingId, array $opts = []): array
    {
        $base = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->where('training_id', $trainingId);

        return $this->paginateUsers($base, $opts);
    }

    /**
     * Drill-down: the users whose assignment is actively sourced by a given
     * requirement, worst-status first.
     *
     * @param  array<string, mixed>  $opts  page, per_page
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function usersForRequirement(Organization $org, string $requirementId, array $opts = []): array
    {
        $base = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->whereHas('activeSources', fn ($q) => $q
                ->where('sourceable_type', Requirement::class)
                ->where('sourceable_id', $requirementId));

        return $this->paginateUsers($base, $opts);
    }

    /**
     * Shared drill-down pager: hydrate the user (page-bounded, so the join is
     * cheap), order worst-status first, return {data, meta}.
     *
     * @param  EloquentBuilder<TrainingAssignment>  $base
     * @param  array<string, mixed>  $opts
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    /**
     * Per-training status tallies (the detail-page header chips). One indexed
     * GROUP-less aggregate over the stored status.
     *
     * @return array<string, int>
     */
    public function trainingCounts(Organization $org, string $trainingId): array
    {
        $row = DB::table('training_assignments')
            ->where('org_id', $org->id)
            ->where('training_id', $trainingId)
            ->selectRaw(<<<'SQL'
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'due_soon' THEN 1 ELSE 0 END) as due_soon,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status = 'current' THEN 1 ELSE 0 END) as current,
                SUM(CASE WHEN status = 'as_needed' THEN 1 ELSE 0 END) as as_needed,
                COUNT(*) as total
            SQL)
            ->first();

        return [
            'overdue' => (int) ($row->overdue ?? 0),
            'due_soon' => (int) ($row->due_soon ?? 0),
            'not_started' => (int) ($row->not_started ?? 0),
            'current' => (int) ($row->current ?? 0),
            'as_needed' => (int) ($row->as_needed ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    private function paginateUsers(EloquentBuilder $base, array $opts): array
    {
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 10)));
        $page = max(1, (int) ($opts['page'] ?? 1));

        // Optional status filter (chips) — status is a TA column, no join.
        if (in_array($opts['status'] ?? null, self::BUCKETS, true)) {
            $base->where('status', $opts['status']);
        }

        // Optional search across the user's name/email + the profile fields the
        // detail list shows (EE# / dept / location) + tag names. Via the
        // relation so there's no join (and no org_id ambiguity).
        if ($like = $this->searchLike($opts)) {
            $base->whereHas('user', fn ($u) => $u->where(function ($w) use ($like) {
                foreach (['f_name', 'm_name', 'l_name', 'email', 'employee_number', 'department', 'location'] as $col) {
                    $w->orWhereRaw("LOWER({$col}) LIKE ?", [$like]);
                }
                // Tag-name match. The morph relation would compare uuid users.id
                // to varchar taggables.taggable_id (Postgres rejects it), so use
                // the explicit CAST subquery (same pattern as UsersController).
                $w->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->whereRaw('taggables.taggable_id = CAST(users.id AS text)')
                    ->where('taggables.taggable_type', User::class)
                    ->whereNull('tags.deleted_at')
                    ->whereRaw('LOWER(tags.name) LIKE ?', [$like]));
            }));
        }

        // Worst-first by the canonical bucket order, then soonest expiry.
        $rank = collect(self::BUCKETS)
            ->map(fn (string $b, int $i) => "WHEN '{$b}' THEN {$i}")
            ->implode(' ');

        $paginator = $base
            ->with([
                'user:id,prefix_name,f_name,m_name,l_name,suffix_name,employee_number,department,location',
                'user.tags:id',
            ])
            ->orderByRaw("CASE status {$rank} ELSE 99 END")
            ->orderByRaw('expires_at IS NULL') // non-null expiries first
            ->orderBy('expires_at')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (TrainingAssignment $ta) => [
                'user_id' => $ta->user_id,
                'name' => $ta->user?->sort_name,
                'status' => $ta->status,
                'expires_at' => $ta->expires_at?->toDateString(),
                'last_completed_at' => $ta->last_completed_at?->toDateString(),
                'employee_number' => $ta->user?->employee_number,
                'department' => $ta->user?->department,
                'location' => $ta->user?->location,
                // Feeds TagsListCell (hydrates the tags store; no per-row fetch).
                'tag_ids' => $ta->user?->tags->pluck('id')->all() ?? [],
            ])->all(),
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
     * Apply the bucket-count aggregation, grouping, sort, and paging shared by
     * every rollup dimension. Counts come straight off the stored status.
     *
     * @param  array<string, mixed>  $opts
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    private function aggregate(Builder $base, string $idCol, string $nameCol, array $opts, string $statusCol = 'ta.status'): array
    {
        $buckets = <<<SQL
            COUNT(*) as total,
            SUM(CASE WHEN {$statusCol} = 'overdue' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN {$statusCol} = 'due_soon' THEN 1 ELSE 0 END) as due_soon,
            SUM(CASE WHEN {$statusCol} = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN {$statusCol} = 'current' THEN 1 ELSE 0 END) as current,
            SUM(CASE WHEN {$statusCol} = 'as_needed' THEN 1 ELSE 0 END) as as_needed
        SQL;

        $query = $base
            ->groupBy($idCol, $nameCol)
            ->selectRaw("{$idCol} as id, {$nameCol} as name, {$buckets}");

        $sort = in_array($opts['sort'] ?? null, self::SORTABLE, true) ? $opts['sort'] : 'overdue';
        $dir = ($opts['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        // $sort is whitelisted; order by the aggregate alias, then name for a
        // stable, page-consistent ordering.
        $query->orderByRaw("{$sort} {$dir}")->orderBy('name');

        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $page = max(1, (int) ($opts['page'] ?? 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total' => (int) $row->total,
                'counts' => [
                    'overdue' => (int) $row->overdue,
                    'due_soon' => (int) $row->due_soon,
                    'not_started' => (int) $row->not_started,
                    'current' => (int) $row->current,
                    'as_needed' => (int) $row->as_needed,
                ],
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
