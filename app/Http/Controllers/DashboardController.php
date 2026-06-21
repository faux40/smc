<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Services\TrainingStatusService;
use App\Support\CompletionSerializer;
use App\Support\SourceChips;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 14 dashboard endpoints. One method per widget — when the
 * future user-prefs phase lands and widgets become add/removable
 * each widget already owns its own fetch lifecycle, no controller
 * surgery needed.
 *
 * Manager+ gate mirrors the AssignmentPolicy + CompletionPolicy
 * widening from 13.x. Self-only users still have /users/{id}.
 */
class DashboardController extends Controller
{
    private const MANAGER_PLUS_ROLES = ['Owner', 'SuperAdmin', 'Admin', 'Manager'];

    private const RECENT_COMPLETIONS_LIMIT = 10;

    /** Worst-first ordering for the needs-action rows (K2). */
    private const ACTION_RANK = [
        TrainingStatusService::STATUS_OVERDUE => 0,
        TrainingStatusService::STATUS_NOT_STARTED => 1,
        TrainingStatusService::STATUS_DUE_SOON => 2,
    ];

    public function __construct(private readonly TrainingStatusService $status) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->status->orgSummary($this->orgFor($request)),
        );
    }

    /**
     * Server-paged all-users compliance ({data, meta}). The per-user summary
     * is computed once, then searched (name/email), sorted, and sliced to a
     * page so a large org doesn't ship/render thousands of rows at once. Sort
     * keys: name, status (worst-first), overdue count, due_soon count.
     */
    public function usersCompliance(Request $request): JsonResponse
    {
        $this->authorize($request);

        $rows = $this->status->usersComplianceSummary($this->orgFor($request));

        $q = trim(mb_strtolower((string) $request->query('q', '')));
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn (array $r) => str_contains(mb_strtolower((string) $r['name']), $q)
                || str_contains(mb_strtolower((string) ($r['email'] ?? '')), $q)));
        }

        // Worst-first status precedence (overdue → … → none last).
        $statusRank = array_flip(TrainingStatusService::STATUSES);
        $rankOf = fn (string $s): int => $statusRank[$s] ?? 99;

        $sort = in_array($request->query('sort'), ['name', 'status', 'overdue', 'due_soon'], true)
            ? $request->query('sort')
            : 'overdue';

        usort($rows, function (array $a, array $b) use ($sort, $rankOf): int {
            $primary = match ($sort) {
                'name' => strcasecmp((string) $a['name'], (string) $b['name']),
                'status' => $rankOf($a['overall_status']) <=> $rankOf($b['overall_status']),
                'due_soon' => ($a['counts']['due_soon'] ?? 0) <=> ($b['counts']['due_soon'] ?? 0),
                default => ($a['counts']['overdue'] ?? 0) <=> ($b['counts']['overdue'] ?? 0),
            };

            // Stable, page-consistent tiebreak.
            return $primary !== 0
                ? $primary
                : (strcasecmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['user_id'], (string) $b['user_id']));
        });

        if ($request->query('dir') !== 'asc') {
            $rows = array_reverse($rows);
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $request->query('page', 1), $lastPage));

        return response()->json([
            'data' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * K2 — flat actionable rows for the "Needs action" widget: every TA
     * whose canonical status is overdue / not_started / due_soon, with
     * user name + source chips, worst first (most-overdue at the top).
     * Grouping/filtering happens client-side.
     */
    public function needsAction(Request $request): JsonResponse
    {
        $this->authorize($request);

        $org = $this->orgFor($request);
        $window = $org->expiringSoonDays();

        $tas = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->with(['user:id,f_name,m_name,l_name,email', 'activeSources'])
            ->get();

        $names = SourceChips::names($tas);

        $rows = $tas
            ->map(fn (TrainingAssignment $ta) => [
                'ta' => $ta,
                'status' => $this->status->statusFor($ta, $window),
            ])
            ->filter(fn (array $x) => isset(self::ACTION_RANK[$x['status']]))
            ->map(fn (array $x) => [
                'id' => $x['ta']->id,
                'user_id' => $x['ta']->user_id,
                'user_name' => $x['ta']->user?->sort_name,
                'training_id' => $x['ta']->training_id,
                'training_name' => $x['ta']->name,
                'status' => $x['status'],
                'expires_at' => $x['ta']->expires_at?->toDateString(),
                'days_until_due' => $this->status->daysUntilDue($x['ta']),
                'sources' => SourceChips::for($x['ta'], $names),
            ])
            ->sortBy([
                fn (array $a, array $b) => self::ACTION_RANK[$a['status']] <=> self::ACTION_RANK[$b['status']],
                fn (array $a, array $b) => ($a['days_until_due'] ?? PHP_INT_MAX) <=> ($b['days_until_due'] ?? PHP_INT_MAX),
                fn (array $a, array $b) => strcasecmp((string) $a['user_name'], (string) $b['user_name']),
            ])
            ->values();

        return response()->json($rows);
    }

    public function recentCompletions(Request $request): JsonResponse
    {
        $this->authorize($request);

        $org = $this->orgFor($request);

        $completions = Completion::query()
            ->where('org_id', $org->id)
            ->with(['user:id,f_name,m_name,l_name,email', 'rqmtElements:id'])
            ->orderBy('completion_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(self::RECENT_COMPLETIONS_LIMIT)
            ->get();

        // Shared M1 serializer + the user name; credits_count is the
        // effective credit (pivot ∪ module identity), so class-issued
        // completions no longer read as zero credits.
        $names = $completions->pluck('user', 'user_id')->map(fn ($u) => $u?->sort_name);
        $rows = collect(CompletionSerializer::collection($completions))
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'user_name' => $names[$row['user_id']] ?? null,
                'module_label' => $row['training_name'] ?? $row['module_type'],
                'completion_date' => $row['completion_date'],
                'expire_date' => $row['expire_date'],
                'credits_count' => count($row['effective_element_ids']),
            ]);

        return response()->json($rows);
    }

    private function authorize(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole(self::MANAGER_PLUS_ROLES),
            403,
        );
    }

    private function orgFor(Request $request): Organization
    {
        // Organization itself is the tenant root (no org_id of its own),
        // so we don't need to bypass any scope here. The downstream
        // service walks per-user queries which the global org scope
        // already filters.
        return Organization::query()
            ->findOrFail($request->user()->org_id);
    }
}
