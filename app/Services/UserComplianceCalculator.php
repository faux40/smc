<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes per-element compliance status for a user, then rolls up to
 * the per-assignment view shown on the user detail page.
 *
 * Status math (per element):
 *   - `as_needed`: always **current**
 *   - `initial_only`: ≥1 crediting completion → current; else past
 *     start_date → overdue; else never_started
 *   - `repeating` (with std_freq):
 *       next_due = completion.expire_date ?? completion.completion_date
 *                  + element.std_freq.repeat_days
 *       past → overdue; within $dueSoonDays → due_soon; else current
 *       no crediting completion + past start_date → overdue
 *
 * Assignment rollup is the worst-of element statuses
 * (overdue ↣ due_soon ↣ current ↣ never_started ↣ inactive). An
 * assignment whose `end_date` is in the past short-circuits to
 * `inactive` — it doesn't pollute active groups.
 *
 * Element timing fields are the operative ones (copied from the source
 * Training/module at create-time per the schema). The Assignment row
 * carries timing too but they're a per-(user, requirement) display
 * snapshot, not the per-element compliance schedule.
 */
class UserComplianceCalculator
{
    /**
     * Default heads-up window for "due soon" classification. The
     * controller can override via the constructor.
     */
    private const DEFAULT_DUE_SOON_DAYS = 60;

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_DUE_SOON = 'due_soon';

    public const STATUS_CURRENT = 'current';

    public const STATUS_NEVER_STARTED = 'never_started';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Ordered worst → best. Rollup picks the earliest matching status.
     */
    private const STATUS_RANK = [
        self::STATUS_OVERDUE,
        self::STATUS_DUE_SOON,
        self::STATUS_CURRENT,
        self::STATUS_NEVER_STARTED,
        self::STATUS_INACTIVE,
    ];

    public function __construct(
        private readonly int $dueSoonDays = self::DEFAULT_DUE_SOON_DAYS,
    ) {}

    /**
     * @return array{
     *     groups: array<string, array<int, array<string, mixed>>>,
     *     completions: array<int, array<string, mixed>>
     * }
     */
    public function compute(User $user, ?CarbonImmutable $now = null, ?Collection $freqs = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        // Load everything once. Eager-load the requirement's elements
        // so we never N+1 inside the loop.
        $assignments = Assignment::query()
            ->where('user_id', $user->id)
            ->with(['requirement.elements'])
            ->orderBy('start_date')
            ->get();

        $completions = Completion::query()
            ->where('user_id', $user->id)
            ->with('rqmtElements:id')
            ->orderBy('completion_date', 'desc')
            ->get();

        // Std-frequency repeat_days lookup keyed by id; absent rows
        // (deleted frequency) fall back to "treat as not yet due". This is
        // org-level data, so the org rollups pass it in once rather than
        // making compute() re-query it for every user in the loop.
        $freqs = $freqs ?? $this->loadFrequencies($user->org_id);

        // Pre-index completions by element id for O(1) lookup inside the
        // per-element loop. Each element id maps to the user's completions
        // that credit it, already sorted newest-first.
        $completionsByElement = $this->indexCompletionsByElement($completions);

        $groups = [
            self::STATUS_OVERDUE => [],
            self::STATUS_DUE_SOON => [],
            self::STATUS_CURRENT => [],
            self::STATUS_NEVER_STARTED => [],
            self::STATUS_INACTIVE => [],
        ];

        foreach ($assignments as $assignment) {
            $row = $this->buildAssignmentRow($assignment, $completionsByElement, $freqs, $now);
            $groups[$row['status']][] = $row;
        }

        // Surface every completion the user has, irrespective of whether
        // it credits a current assignment (the "credit for unassigned"
        // path stays visible per v15).
        $serialisedCompletions = $completions->map(
            fn (Completion $c) => $this->serialiseCompletion($c),
        )->all();

        return [
            'groups' => $groups,
            'completions' => $serialisedCompletions,
        ];
    }

    /**
     * Org-level std-frequency lookup keyed by id (incl. soft-deleted, which
     * fall back to "not yet due"). Hoisted so the per-user rollup loops load
     * it once instead of re-querying it for every user.
     */
    private function loadFrequencies(string $orgId): Collection
    {
        return StdFrequency::query()
            ->where('org_id', $orgId)
            ->withTrashed()
            ->get()
            ->keyBy('id');
    }

    /**
     * Org-wide rollup driving Phase 14 dashboard widgets. Sums every
     * user's per-assignment status (computed by compute()) into a single
     * counts map plus headline totals.
     *
     * Iterates users — O(users), fine for orgs in the hundreds (DevDataSeeder
     * ships 21 in BG). Each user costs a small fixed number of queries
     * (compute() is eager-loaded + org-level std_frequencies is loaded once
     * here and passed in, not re-queried per user). CompliancePerformanceTest
     * pins both properties + a linear query budget. CEILING: past a few
     * thousand users this should move to a single query-based aggregation;
     * deferred until a real org approaches it so the per-user math stays the
     * single source of truth (dashboard can't drift from the detail page).
     *
     * @return array{
     *     counts: array{overdue:int, due_soon:int, current:int, never_started:int, inactive:int},
     *     total_assignments: int,
     *     total_users: int,
     *     users_with_overdue: int
     * }
     */
    public function summarizeOrg(Organization $org, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $counts = [
            self::STATUS_OVERDUE => 0,
            self::STATUS_DUE_SOON => 0,
            self::STATUS_CURRENT => 0,
            self::STATUS_NEVER_STARTED => 0,
            self::STATUS_INACTIVE => 0,
        ];

        $totalAssignments = 0;
        $usersWithOverdue = 0;

        $users = $this->orgUsers($org);
        $freqs = $this->loadFrequencies($org->id);

        foreach ($users as $user) {
            $result = $this->compute($user, $now, $freqs);
            $hasOverdue = false;

            foreach ($result['groups'] as $status => $rows) {
                $n = count($rows);
                $counts[$status] += $n;
                $totalAssignments += $n;
                if ($status === self::STATUS_OVERDUE && $n > 0) {
                    $hasOverdue = true;
                }
            }

            if ($hasOverdue) {
                $usersWithOverdue++;
            }
        }

        return [
            'counts' => $counts,
            'total_assignments' => $totalAssignments,
            'total_users' => $users->count(),
            'users_with_overdue' => $usersWithOverdue,
        ];
    }

    /**
     * Top N users in the org by overdue-assignment count, worst first.
     * Drives the Phase 14 dashboard widget and the Phase 15.6 weekly
     * manager digest — both read this single source so they can't drift.
     *
     * @return array<int, array{user_id: string, name: string, email: ?string, overdue_count: int}>
     */
    public function topOverdueUsers(Organization $org, int $limit, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $rows = [];
        $freqs = $this->loadFrequencies($org->id);
        foreach ($this->orgUsers($org) as $user) {
            $result = $this->compute($user, $now, $freqs);
            $overdueCount = count($result['groups'][self::STATUS_OVERDUE]);
            if ($overdueCount === 0) {
                continue;
            }
            $rows[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'overdue_count' => $overdueCount,
            ];
        }

        usort($rows, fn ($a, $b) => $b['overdue_count'] <=> $a['overdue_count']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Up to N (user, assignment) pairs sitting in their due-soon
     * window, earliest due first. Drives the dashboard widget and the
     * manager digest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topDueSoon(Organization $org, int $limit, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $items = [];
        $freqs = $this->loadFrequencies($org->id);
        foreach ($this->orgUsers($org) as $user) {
            $result = $this->compute($user, $now, $freqs);
            foreach ($result['groups'][self::STATUS_DUE_SOON] as $row) {
                $items[] = array_merge($row, [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                ]);
            }
        }

        usort(
            $items,
            fn ($a, $b) => ($a['days_until_due'] ?? PHP_INT_MAX) <=> ($b['days_until_due'] ?? PHP_INT_MAX),
        );

        return array_slice($items, 0, $limit);
    }

    /**
     * Per-user compliance summary for the dashboard's full-width all-users
     * list — one row each with per-status counts, an overall status, and the
     * user's attached tag ids. Loops users (the same O(users) ceiling as the
     * other rollups); std_frequencies + tags are loaded once up front so
     * there's no per-user N+1.
     *
     * @return array<int, array{
     *     user_id: string, name: string, email: ?string,
     *     counts: array<string,int>, overall_status: string, tag_ids: array<int,string>
     * }>
     */
    public function usersComplianceSummary(Organization $org, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $freqs = $this->loadFrequencies($org->id);
        $users = $this->orgUsers($org)->load('tags:id');

        $rows = [];

        foreach ($users as $user) {
            $result = $this->compute($user, $now, $freqs);

            $counts = [];

            foreach (self::STATUS_RANK as $status) {
                $counts[$status] = count($result['groups'][$status]);
            }

            $rows[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'counts' => $counts,
                'overall_status' => $this->overallStatus($counts),
                'tag_ids' => $user->tags->pluck('id')->all(),
            ];
        }

        return $rows;
    }

    /**
     * Worst non-empty status by STATUS_RANK precedence (overdue first); 'none'
     * when the user has no assignments at all.
     *
     * @param  array<string,int>  $counts
     */
    private function overallStatus(array $counts): string
    {
        foreach (self::STATUS_RANK as $status) {
            if (($counts[$status] ?? 0) > 0) {
                return $status;
            }
        }

        return 'none';
    }

    /**
     * Every user in the org. Soft-deleted users are excluded by the
     * model's global scope; the org global scope is a no-op here since
     * we filter on `org_id` explicitly.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function orgUsers(Organization $org): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->where('org_id', $org->id)
            ->get();
    }

    /**
     * @return array<string, Collection<int, Completion>>
     */
    private function indexCompletionsByElement(Collection $completions): array
    {
        $index = [];
        foreach ($completions as $completion) {
            foreach ($completion->rqmtElements as $element) {
                $index[$element->id] = $index[$element->id] ?? collect();
                $index[$element->id]->push($completion);
            }
        }

        return $index;
    }

    /**
     * @param  array<string, Collection<int, Completion>>  $completionsByElement
     * @param  Collection<string, StdFrequency>  $freqs
     * @return array<string, mixed>
     */
    private function buildAssignmentRow(
        Assignment $assignment,
        array $completionsByElement,
        Collection $freqs,
        CarbonImmutable $now,
    ): array {
        $startDate = $assignment->start_date
            ? CarbonImmutable::parse($assignment->start_date)
            : null;
        $endDate = $assignment->end_date
            ? CarbonImmutable::parse($assignment->end_date)
            : null;

        // end_date in the past → assignment is deactivated. Short-circuit
        // so deactivated rows don't pollute the active buckets.
        if ($endDate !== null && $endDate->lt($now)) {
            return $this->row($assignment, self::STATUS_INACTIVE, null, null, null);
        }

        $elements = $assignment->requirement?->elements ?? collect();
        if ($elements->isEmpty()) {
            // No elements means there's nothing to satisfy — treat as
            // current. Phase 9 already forbids orphan requirements via UX,
            // but the schema tolerates them.
            return $this->row($assignment, self::STATUS_CURRENT, null, null, null);
        }

        $elementStatuses = [];
        $earliestDue = null;
        $lastCompletionDate = null;

        foreach ($elements as $element) {
            $eStatus = $this->elementStatus(
                $element,
                $completionsByElement[$element->id] ?? collect(),
                $freqs,
                $startDate,
                $now,
            );
            $elementStatuses[] = $eStatus['status'];

            if ($eStatus['next_due'] !== null) {
                if ($earliestDue === null || $eStatus['next_due']->lt($earliestDue)) {
                    $earliestDue = $eStatus['next_due'];
                }
            }
            if ($eStatus['last_completion_date'] !== null) {
                if ($lastCompletionDate === null
                    || $eStatus['last_completion_date']->gt($lastCompletionDate)) {
                    $lastCompletionDate = $eStatus['last_completion_date'];
                }
            }
        }

        $rollup = $this->worstStatus($elementStatuses);

        $daysUntilDue = $earliestDue !== null
            ? (int) $now->startOfDay()->diffInDays($earliestDue->startOfDay(), false)
            : null;

        return $this->row(
            $assignment,
            $rollup,
            $lastCompletionDate?->toDateString(),
            $earliestDue?->toDateString(),
            $daysUntilDue,
        );
    }

    /**
     * @param  Collection<int, Completion>  $crediting
     * @param  Collection<string, StdFrequency>  $freqs
     * @return array{status: string, next_due: ?CarbonImmutable, last_completion_date: ?CarbonImmutable}
     */
    private function elementStatus(
        RqmtElement $element,
        Collection $crediting,
        Collection $freqs,
        ?CarbonImmutable $startDate,
        CarbonImmutable $now,
    ): array {
        // Always-current shortcut.
        if ($element->as_needed) {
            $latest = $crediting->first();

            return [
                'status' => self::STATUS_CURRENT,
                'next_due' => null,
                'last_completion_date' => $latest
                    ? CarbonImmutable::parse($latest->completion_date)
                    : null,
            ];
        }

        $latest = $crediting->first();

        if ($latest === null) {
            // No crediting completion — overdue if past start; else
            // "never_started" (no missed clock yet).
            if ($startDate !== null && $startDate->lt($now)) {
                return ['status' => self::STATUS_OVERDUE, 'next_due' => $startDate, 'last_completion_date' => null];
            }

            return ['status' => self::STATUS_NEVER_STARTED, 'next_due' => $startDate, 'last_completion_date' => null];
        }

        $lastDate = CarbonImmutable::parse($latest->completion_date);

        // Initial-only is satisfied forever once completed.
        if ($element->initial_only) {
            return [
                'status' => self::STATUS_CURRENT,
                'next_due' => null,
                'last_completion_date' => $lastDate,
            ];
        }

        // Repeating element. Next-due priority:
        //   1. completion.expire_date (if the completer set one — e.g.
        //      a cert with a fixed expiry).
        //   2. completion_date + std_frequency.repeat_days.
        //   3. If neither resolvable (no freq), treat as current — no
        //      schedule to violate. (Defensive; this combo shouldn't
        //      happen in practice because RqmtElementRequest requires
        //      std_freq_id when repeating.)
        $nextDue = null;
        if ($latest->expire_date) {
            $nextDue = CarbonImmutable::parse($latest->expire_date);
        } elseif ($element->std_freq_id && $freqs->has($element->std_freq_id)) {
            $nextDue = $lastDate->addDays($freqs[$element->std_freq_id]->repeat_days);
        } else {
            return [
                'status' => self::STATUS_CURRENT,
                'next_due' => null,
                'last_completion_date' => $lastDate,
            ];
        }

        if ($nextDue->lt($now)) {
            return ['status' => self::STATUS_OVERDUE, 'next_due' => $nextDue, 'last_completion_date' => $lastDate];
        }
        if ($nextDue->lte($now->addDays($this->dueSoonDays))) {
            return ['status' => self::STATUS_DUE_SOON, 'next_due' => $nextDue, 'last_completion_date' => $lastDate];
        }

        return ['status' => self::STATUS_CURRENT, 'next_due' => $nextDue, 'last_completion_date' => $lastDate];
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function worstStatus(array $statuses): string
    {
        foreach (self::STATUS_RANK as $rank) {
            if (in_array($rank, $statuses, true)) {
                return $rank;
            }
        }

        return self::STATUS_CURRENT;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        Assignment $assignment,
        string $status,
        ?string $lastCompletionDate,
        ?string $nextDueDate,
        ?int $daysUntilDue,
    ): array {
        return [
            'assignment_id' => $assignment->id,
            'requirement_id' => $assignment->requirement_id,
            'requirement_name' => $assignment->requirement?->name ?? $assignment->name,
            'assignment_name' => $assignment->name,
            'timing' => $this->timingLabel($assignment),
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'status' => $status,
            'last_completion_date' => $lastCompletionDate,
            'next_due_date' => $nextDueDate,
            'days_until_due' => $daysUntilDue,
        ];
    }

    private function timingLabel(Assignment $a): string
    {
        if ($a->initial_only) {
            return 'Initial-only';
        }
        if ($a->as_needed) {
            return 'As-needed';
        }
        if ($a->repeating) {
            return 'Repeating';
        }

        return '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseCompletion(Completion $c): array
    {
        return [
            'id' => $c->id,
            'module_type' => $c->module_type,
            'module_id' => $c->module_id,
            'completion_date' => optional($c->completion_date)->toDateString(),
            'certification_date' => optional($c->certification_date)->toDateString(),
            'expire_date' => optional($c->expire_date)->toDateString(),
            'cert_ident' => $c->cert_ident,
            'notes' => $c->notes,
            'rqmt_element_ids' => $c->rqmtElements->pluck('id')->all(),
        ];
    }
}
