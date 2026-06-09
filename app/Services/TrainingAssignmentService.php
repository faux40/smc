<?php

namespace App\Services;

use App\Actions\RecalculateTrainingStatus;
use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentDeleted;
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

    /**
     * Remove one training from a requirement set for a user.
     *
     * Deletes the requirement source on the target TA (and the TA itself if
     * it becomes sourceless). Converts every other TA for the same
     * (user, requirement) pair to a direct source so they remain assigned
     * but are no longer tracked under the requirement.
     *
     * @return array{deleted_id: string|null, updated_ids: list<string>}
     */
    public function breakFromRequirement(
        string $orgId,
        TrainingAssignment $ta,
        string $requirementId,
    ): array {
        $targetSource = AssignmentSource::where('training_assignment_id', $ta->id)
            ->where('sourceable_type', Requirement::class)
            ->where('sourceable_id', $requirementId)
            ->first();

        if (! $targetSource) {
            abort(422, 'This training assignment is not sourced by the given requirement.');
        }

        // Convert sibling TAs (same user + same requirement, excluding target).
        $siblingSources = AssignmentSource::where('sourceable_type', Requirement::class)
            ->where('sourceable_id', $requirementId)
            ->whereHas(
                'trainingAssignment',
                fn ($q) => $q->where('org_id', $orgId)
                    ->where('user_id', $ta->user_id)
                    ->where('id', '!=', $ta->id),
            )
            ->with('trainingAssignment')
            ->get();

        $updatedIds = [];

        foreach ($siblingSources as $siblingSource) {
            $siblingTa = $siblingSource->trainingAssignment;
            $siblingSource->delete();
            AssignmentSource::create([
                'training_assignment_id' => $siblingTa->id,
                'sourceable_type' => null,
                'sourceable_id' => null,
                'added_at' => now(),
            ]);
            event(new TrainingAssignmentCreated($siblingTa->load('activeSources')));
            $updatedIds[] = $siblingTa->id;
        }

        // Remove the requirement source from the target TA.
        $targetSource->delete();

        if ($ta->activeSources()->count() === 0) {
            event(new TrainingAssignmentDeleted($ta->id, $ta->user_id, $ta->training_id, $ta->org_id));
            $ta->delete();

            return ['deleted_id' => $ta->id, 'updated_ids' => $updatedIds];
        }

        // TA has another source (e.g. direct) — keep it but broadcast updated sources.
        event(new TrainingAssignmentCreated($ta->load('activeSources')));
        $updatedIds[] = $ta->id;

        return ['deleted_id' => null, 'updated_ids' => $updatedIds];
    }

    /**
     * Remove all AssignmentSource rows for the given (user, requirement) pair
     * and delete any TrainingAssignment that has no remaining active sources.
     *
     * @return array{deleted_ids: list<string>, updated_ids: list<string>}
     */
    public function removeRequirementSources(string $orgId, string $userId, string $requirementId): array
    {
        $sources = AssignmentSource::where('sourceable_type', Requirement::class)
            ->where('sourceable_id', $requirementId)
            ->whereHas('trainingAssignment', fn ($q) => $q->where('org_id', $orgId)->where('user_id', $userId))
            ->with('trainingAssignment')
            ->get();

        $deletedIds = [];
        $updatedIds = [];

        foreach ($sources as $source) {
            $ta = $source->trainingAssignment;
            $source->delete();

            if ($ta->activeSources()->count() === 0) {
                event(new TrainingAssignmentDeleted($ta->id, $ta->user_id, $ta->training_id, $ta->org_id));
                $ta->delete();
                $deletedIds[] = $ta->id;
            } else {
                $updatedIds[] = $ta->id;
            }
        }

        return ['deleted_ids' => $deletedIds, 'updated_ids' => $updatedIds];
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
