<?php

namespace App\Http\Controllers;

use App\Events\TrainingAssignmentsBulkChanged;
use App\Http\Requests\BulkTrainingAssignmentRequest;
use App\Services\TrainingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BulkTrainingAssignmentsController extends Controller
{
    public function __construct(
        private TrainingAssignmentService $service,
    ) {}

    public function store(BulkTrainingAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = $request->user()->org_id;

        $result = $data['source_type'] === 'requirement'
            ? $this->service->bulkAssignFromRequirement($orgId, $data['user_ids'], $data['requirement_id'])
            : $this->service->bulkAssignDirect($orgId, $data['user_ids'], $data['training_id']);

        // One org-channel signal for the whole batch instead of a broadcast per
        // created TA (F4) — peer tabs debounce-refetch their current page.
        if ($result['created'] > 0) {
            event(new TrainingAssignmentsBulkChanged($orgId, actorId: Auth::id()));
        }

        return response()->json([
            'created_count' => $result['created'],
            'skipped_count' => $result['skipped'],
        ], 201);
    }
}
