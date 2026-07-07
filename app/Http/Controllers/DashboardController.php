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
     * Server-paged all-users compliance ({data, meta}). Search (name/email),
     * sort (name / status worst-first / overdue / due_soon), and pagination
     * all run in SQL over the materialized status, so a large org never ships
     * or hydrates thousands of rows for one page.
     */
    public function usersCompliance(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->status->usersComplianceSummary($this->orgFor($request), [
                'q' => $request->query('q'),
                'sort' => $request->query('sort'),
                'dir' => $request->query('dir'),
                'page' => $request->query('page'),
                'per_page' => $request->query('per_page'),
            ]),
        );
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

        // Filter + order on the indexed status column; expiry ascending puts
        // the most-overdue (most-negative days) first within each bucket, with
        // nulls (not_started) last. No per-assignment status recomputation.
        $tas = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->whereIn('status', array_keys(self::ACTION_RANK))
            ->with(['user:id,f_name,m_name,l_name,email', 'activeSources'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'not_started' THEN 1 ELSE 2 END")
            ->orderByRaw('expires_at IS NULL') // non-null expiries first
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();

        $names = SourceChips::names($tas);

        $rows = $tas->map(fn (TrainingAssignment $ta) => [
            'id' => $ta->id,
            'user_id' => $ta->user_id,
            'user_name' => $ta->user?->sort_name,
            'training_id' => $ta->training_id,
            'training_name' => $ta->name,
            'status' => $ta->status,
            'expires_at' => $ta->expires_at?->toDateString(),
            'days_until_due' => $this->status->daysUntilDue($ta),
            'sources' => SourceChips::for($ta, $names),
        ])->values();

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
