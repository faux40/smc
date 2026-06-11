<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Services\TrainingStatusService;
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

    public function usersCompliance(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->status->usersComplianceSummary($this->orgFor($request)),
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
                'user_name' => $x['ta']->user?->name,
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
            ->with(['user:id,f_name,l_name,email', 'rqmtElements:id'])
            ->orderBy('completion_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(self::RECENT_COMPLETIONS_LIMIT)
            ->get();

        // Cache training names so we can label the module identity per
        // row without N+1 lookups.
        $trainingIds = $completions
            ->where('module_type', Training::class)
            ->pluck('module_id')
            ->unique()
            ->all();
        $trainings = Training::query()
            ->whereIn('id', $trainingIds)
            ->get()
            ->keyBy('id');

        return response()->json($completions->map(function (Completion $c) use ($trainings) {
            $moduleLabel = $c->module_type === Training::class
                ? ($trainings[$c->module_id]->name ?? 'Training')
                : $c->module_type;

            return [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'user_name' => $c->user?->name,
                'module_label' => $moduleLabel,
                'completion_date' => optional($c->completion_date)->toDateString(),
                'expire_date' => optional($c->expire_date)->toDateString(),
                'credits_count' => $c->rqmtElements->count(),
            ];
        }));
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
