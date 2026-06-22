<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\TrainingAssignment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
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
    private function paginateUsers(EloquentBuilder $base, array $opts): array
    {
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 10)));
        $page = max(1, (int) ($opts['page'] ?? 1));

        // Worst-first by the canonical bucket order, then soonest expiry.
        $rank = collect(self::BUCKETS)
            ->map(fn (string $b, int $i) => "WHEN '{$b}' THEN {$i}")
            ->implode(' ');

        $paginator = $base
            ->with('user:id,prefix_name,f_name,m_name,l_name,suffix_name')
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
    private function aggregate(Builder $base, string $idCol, string $nameCol, array $opts): array
    {
        $buckets = <<<'SQL'
            COUNT(*) as total,
            SUM(CASE WHEN ta.status = 'overdue' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN ta.status = 'due_soon' THEN 1 ELSE 0 END) as due_soon,
            SUM(CASE WHEN ta.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ta.status = 'current' THEN 1 ELSE 0 END) as current,
            SUM(CASE WHEN ta.status = 'as_needed' THEN 1 ELSE 0 END) as as_needed
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
