<?php

namespace App\Http\Controllers;

use App\Models\TrainingAssignment;
use App\Services\AssignmentReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * F10 Part B — manual "Remind" nudges. A manager can re-send the notification
 * appropriate to an assignment's current status (overdue → AssignmentOverdue
 * + supervisor CC; due_soon / not_started → AssignmentDueSoon), one at a time
 * or over a selection. Manager+ gated, mirroring bulk-assign (the create gate
 * on TrainingAssignment). Org-scoping is enforced by route-model binding for
 * the single endpoint and an explicit org whereIn for the bulk endpoint.
 */
class AssignmentRemindController extends Controller
{
    public function __construct(private readonly AssignmentReminderService $reminder) {}

    /**
     * Remind the person about a single assignment. Returns 422 when there is
     * nothing to remind about (a current / as-needed assignment).
     */
    public function store(TrainingAssignment $trainingAssignment): JsonResponse
    {
        Gate::authorize('create', TrainingAssignment::class);

        $result = $this->reminder->remind($trainingAssignment);

        if (! $result['sent']) {
            return response()->json([
                'message' => 'Nothing to remind — this assignment is not overdue or due.',
                'status' => $result['status'],
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Remind selected assignments. Skips ids outside the actor's org and
     * assignments with nothing to remind about; returns the tallies.
     */
    public function bulk(Request $request): JsonResponse
    {
        Gate::authorize('create', TrainingAssignment::class);

        $data = $request->validate([
            'training_assignment_ids' => ['required', 'array', 'min:1'],
            'training_assignment_ids.*' => ['string'],
        ]);

        $requested = count($data['training_assignment_ids']);

        // Org-scoped fetch — the global scope binds the actor's org, so a
        // cross-org id simply never loads (and counts as skipped).
        $assignments = TrainingAssignment::query()
            ->where('org_id', $request->user()->org_id)
            ->whereIn('id', $data['training_assignment_ids'])
            ->with('user.supervisor', 'organization')
            ->get();

        $reminded = 0;
        $supervisors = 0;

        foreach ($assignments as $ta) {
            $result = $this->reminder->remind($ta);
            if ($result['sent']) {
                $reminded++;
                if ($result['supervisor_notified']) {
                    $supervisors++;
                }
            }
        }

        return response()->json([
            'reminded_count' => $reminded,
            'skipped_count' => $requested - $reminded,
            'supervisors_notified_count' => $supervisors,
        ]);
    }
}
