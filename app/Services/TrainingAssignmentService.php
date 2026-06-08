<?php

namespace App\Services;

use App\Actions\RecalculateTrainingStatus;
use App\Events\TrainingAssignmentCreated;
use App\Models\AssignmentSource;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use Illuminate\Support\Facades\Auth;

/**
 * Shared logic for creating training assignments and their sources.
 *
 * Used by both TrainingAssignmentsController (single user) and
 * BulkTrainingAssignmentsController (many users at once).
 */
class TrainingAssignmentService
{
    public function __construct(
        private RecalculateTrainingStatus $recalculate,
    ) {}

    /**
     * Assign a single training directly to a user.
     * Creates an AssignmentSource with no sourceable (direct).
     */
    public function assignDirect(string $orgId, string $userId, string $trainingId): TrainingAssignment
    {
        $ta = $this->findOrCreate($orgId, $userId, $trainingId);

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        $this->recalculate->handle($userId, $trainingId);

        event(new TrainingAssignmentCreated($ta->fresh(), actorId: Auth::id()));

        return $ta->fresh();
    }

    /**
     * Explode a requirement into one TrainingAssignment per training element.
     * Each gets an AssignmentSource pointing at the requirement.
     *
     * @return TrainingAssignment[]
     */
    public function assignFromRequirement(string $orgId, string $userId, string $requirementId): array
    {
        $requirement = Requirement::with(['elements' => fn ($q) => $q->where('module_type', Training::class)])
            ->where('org_id', $orgId)
            ->findOrFail($requirementId);

        $created = [];
        foreach ($requirement->elements as $element) {
            $ta = $this->findOrCreate($orgId, $userId, $element->module_id);

            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => Requirement::class,
                'sourceable_id' => $requirement->id,
                'added_at' => now(),
            ]);

            $this->recalculate->handle($userId, $element->module_id);

            event(new TrainingAssignmentCreated($ta->fresh(), actorId: Auth::id()));

            $created[] = $ta->fresh();
        }

        return $created;
    }

    private function findOrCreate(string $orgId, string $userId, string $trainingId): TrainingAssignment
    {
        $training = Training::where('org_id', $orgId)->findOrFail($trainingId);

        return TrainingAssignment::firstOrCreate(
            ['user_id' => $userId, 'training_id' => $trainingId],
            ['org_id' => $orgId, 'name' => $training->name],
        );
    }
}
