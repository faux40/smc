<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkTrainingAssignmentRequest;
use App\Models\User;
use App\Services\TrainingAssignmentService;
use Illuminate\Http\JsonResponse;

class BulkTrainingAssignmentsController extends Controller
{
    public function __construct(
        private TrainingAssignmentService $service,
    ) {}

    public function store(BulkTrainingAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $orgId = $request->user()->org_id;

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($data['user_ids'] as $userId) {
            // Ensure the user belongs to this org (prevents cross-tenant write).
            if (! User::where('id', $userId)->where('org_id', $orgId)->exists()) {
                $skippedCount++;
                continue;
            }

            if ($data['source_type'] === 'requirement') {
                $results = $this->service->assignFromRequirement($orgId, $userId, $data['requirement_id']);
                $createdCount += count($results);
            } else {
                $this->service->assignDirect($orgId, $userId, $data['training_id']);
                $createdCount++;
            }
        }

        return response()->json([
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
        ], 201);
    }
}
