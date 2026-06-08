<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\UserComplianceCalculator;
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

    private const DUE_SOON_LIMIT = 50;

    private const OVERDUE_USERS_LIMIT = 10;

    private const RECENT_COMPLETIONS_LIMIT = 10;

    public function __construct(private readonly UserComplianceCalculator $calculator) {}

    public function summary(Request $request): JsonResponse
    {
        $this->authorize($request);

        $summary = $this->calculator->summarizeOrg($this->orgFor($request));

        return response()->json($summary);
    }

    public function usersCompliance(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->calculator->usersComplianceSummary($this->orgFor($request)),
        );
    }

    public function overdueUsers(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->calculator->topOverdueUsers($this->orgFor($request), self::OVERDUE_USERS_LIMIT),
        );
    }

    public function dueSoon(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(
            $this->calculator->topDueSoon($this->orgFor($request), self::DUE_SOON_LIMIT),
        );
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

    public function trainingSoon(Request $request): JsonResponse
    {
        $this->authorize($request);

        $org = $this->orgFor($request);
        $window = $org->dueSoonDays();
        $today = now()->toDateString();
        $windowEnd = now()->addDays($window)->toDateString();

        // Overdue: never started (null last_completed_at) OR already expired.
        $overdueRows = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->where(function ($q) use ($today) {
                $q->whereNull('last_completed_at')
                    ->orWhere(function ($q2) use ($today) {
                        $q2->whereNotNull('last_completed_at')
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<', $today);
                    });
            })
            ->orderBy('name', 'asc')
            ->get();

        // Due soon: completed, not yet expired, within the org threshold window.
        $dueSoonRows = TrainingAssignment::query()
            ->where('org_id', $org->id)
            ->whereNotNull('last_completed_at')
            ->whereBetween('expires_at', [$today, $windowEnd])
            ->orderBy('expires_at', 'asc')
            ->get();

        $allRows = $overdueRows->merge($dueSoonRows);
        $userIds = $allRows->pluck('user_id')->unique()->all();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $toShape = fn (TrainingAssignment $ta) => [
            'id' => $ta->id,
            'user_id' => $ta->user_id,
            'user_name' => $users[$ta->user_id]?->name ?? 'Unknown',
            'training_name' => $ta->name,
            'expires_at' => $ta->expires_at?->toDateString(),
        ];

        return response()->json([
            'overdue' => $overdueRows->map($toShape)->values(),
            'due_soon' => $dueSoonRows->map($toShape)->values(),
        ]);
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
