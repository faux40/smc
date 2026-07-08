<?php

namespace App\Services;

use App\Actions\RecalculateTrainingStatus;
use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentDeleted;
use App\Models\AssignmentSource;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Notifications\AssignmentCreatedForYou;
use App\Support\RecalcContext;
use Illuminate\Support\Collection;
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

        // recalculate saved fresh date/status columns on a separate instance;
        // pull them back once (not the two fresh() round-trips this used to do).
        $wasCreated = $ta->wasRecentlyCreated;
        $ta->refresh();

        event(new TrainingAssignmentCreated($ta, actorId: Auth::id()));

        if ($wasCreated) {
            $this->notifyAssigned($userId, $ta->name, trainingId: $trainingId);
        }

        return $ta;
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
        $anyNew = false;
        foreach ($requirement->elements as $element) {
            $ta = $this->findOrCreate($orgId, $userId, $element->module_id);
            $anyNew = $anyNew || $ta->wasRecentlyCreated;

            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => Requirement::class,
                'sourceable_id' => $requirement->id,
                'added_at' => now(),
            ]);

            $this->recalculate->handle($userId, $element->module_id);

            // One refresh to pick up recalculated columns instead of two fresh().
            $ta->refresh();
            event(new TrainingAssignmentCreated($ta, actorId: Auth::id()));

            $created[] = $ta;
        }

        // One inbox nudge per requirement set, not one per exploded training.
        if ($anyNew) {
            $this->notifyAssigned($userId, $requirement->name, requirementId: $requirement->id);
        }

        return $created;
    }

    /**
     * Assign one training directly to many users in a single batched pass.
     *
     * The org-membership filter, the training lookup, the already-assigned
     * check, the amber window, and the status recalc are all done once (or as
     * one whereIn) rather than per user, and no per-TA broadcast is emitted —
     * the caller fires a single TrainingAssignmentsBulkChanged instead (F4).
     *
     * @param  array<int, string>  $userIds
     * @return array{created: int, skipped: int}
     */
    public function bulkAssignDirect(string $orgId, array $userIds, string $trainingId): array
    {
        $validUserIds = $this->validUserIds($orgId, $userIds);
        $skipped = count($userIds) - $validUserIds->count();

        if ($validUserIds->isEmpty()) {
            return ['created' => 0, 'skipped' => $skipped];
        }

        $training = Training::where('org_id', $orgId)->findOrFail($trainingId);
        $context = RecalcContext::make($orgId, collect([$training]));

        // One existence check for every (user, training) pair instead of a
        // per-user firstOrCreate SELECT.
        $existing = TrainingAssignment::whereIn('user_id', $validUserIds)
            ->where('training_id', $trainingId)
            ->get()
            ->keyBy('user_id');

        $newUserIds = [];
        foreach ($validUserIds as $userId) {
            $ta = $existing->get($userId)
                ?? $this->createAssignment($orgId, $userId, $training, $context->window);

            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => null,
                'sourceable_id' => null,
                'added_at' => now(),
            ]);

            if ($ta->wasRecentlyCreated) {
                $newUserIds[] = $userId;
            }
        }

        $pairs = $validUserIds->map(fn ($id) => ['user_id' => $id, 'training_id' => $trainingId]);
        $this->recalculate->handleMany($pairs, $context);

        $this->notifyManyAssigned($newUserIds, $training->name, trainingId: $trainingId);

        return ['created' => $validUserIds->count(), 'skipped' => $skipped];
    }

    /**
     * Explode a requirement onto many users at once. Same batching contract as
     * bulkAssignDirect: invariants hoisted, one whereIn existence check, one
     * batched recalc, no per-TA broadcast.
     *
     * @param  array<int, string>  $userIds
     * @return array{created: int, skipped: int}
     */
    public function bulkAssignFromRequirement(string $orgId, array $userIds, string $requirementId): array
    {
        $validUserIds = $this->validUserIds($orgId, $userIds);
        $skipped = count($userIds) - $validUserIds->count();

        if ($validUserIds->isEmpty()) {
            return ['created' => 0, 'skipped' => $skipped];
        }

        $requirement = Requirement::with(['elements' => fn ($q) => $q->where('module_type', Training::class)])
            ->where('org_id', $orgId)
            ->findOrFail($requirementId);

        $elements = $requirement->elements;

        if ($elements->isEmpty()) {
            return ['created' => 0, 'skipped' => $skipped];
        }

        $trainingIds = $elements->pluck('module_id')->unique()->values();
        $trainings = Training::where('org_id', $orgId)->whereIn('id', $trainingIds)->get();
        $trainingsById = $trainings->keyBy('id');

        // Element timings feed the recalc's strictest-source resolution.
        $elements->loadMissing('stdFrequency');
        $context = RecalcContext::make($orgId, $trainings, $elements);

        $existing = TrainingAssignment::whereIn('user_id', $validUserIds)
            ->whereIn('training_id', $trainingIds)
            ->get()
            ->keyBy(fn (TrainingAssignment $ta) => $ta->user_id.'|'.$ta->training_id);

        $pairs = collect();
        $newUserIds = [];
        $created = 0;

        foreach ($validUserIds as $userId) {
            $userIsNew = false;

            foreach ($elements as $element) {
                $trainingId = $element->module_id;
                $key = $userId.'|'.$trainingId;

                $ta = $existing->get($key)
                    ?? $this->createAssignment($orgId, $userId, $trainingsById->get($trainingId), $context->window);
                // Cache so a requirement listing the same training twice reuses
                // the row rather than creating a duplicate.
                $existing->put($key, $ta);

                AssignmentSource::create([
                    'training_assignment_id' => $ta->id,
                    'sourceable_type' => Requirement::class,
                    'sourceable_id' => $requirement->id,
                    'added_at' => now(),
                ]);

                $userIsNew = $userIsNew || $ta->wasRecentlyCreated;
                $pairs->push(['user_id' => $userId, 'training_id' => $trainingId]);
                $created++;
            }

            if ($userIsNew) {
                $newUserIds[] = $userId;
            }
        }

        $this->recalculate->handleMany($pairs, $context);

        $this->notifyManyAssigned($newUserIds, $requirement->name, requirementId: $requirement->id);

        return ['created' => $created, 'skipped' => $skipped];
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
            // Requirement→direct swap changes the timing source (J2).
            $this->recalculate->handle($siblingTa->user_id, $siblingTa->training_id);
            event(new TrainingAssignmentCreated($siblingTa->refresh()->load('activeSources')));
            $updatedIds[] = $siblingTa->id;
        }

        // Remove the requirement source from the target TA.
        $targetSource->delete();

        if ($ta->activeSources()->count() === 0) {
            event(new TrainingAssignmentDeleted($ta->id, $ta->user_id, $ta->training_id, $ta->org_id));
            $ta->delete();

            return ['deleted_id' => $ta->id, 'updated_ids' => $updatedIds];
        }

        // TA has another source (e.g. direct) — keep it; its expiry may loosen
        // now that the requirement's element no longer applies (J2).
        $this->recalculate->handle($ta->user_id, $ta->training_id);
        event(new TrainingAssignmentCreated($ta->refresh()->load('activeSources')));
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
                // Surviving sources may carry looser timing than the removed
                // requirement's element did (J2) — recompute and tell peers.
                $this->recalculate->handle($ta->user_id, $ta->training_id);
                event(new TrainingAssignmentCreated($ta->refresh()->load('activeSources')));
                $updatedIds[] = $ta->id;
            }
        }

        return ['deleted_ids' => $deletedIds, 'updated_ids' => $updatedIds];
    }

    /**
     * Recompute every TA affected by a timing change on a requirement's
     * training element (element edited or soft-deleted) and broadcast the
     * ones whose dates moved so open tabs refresh their pills.
     */
    public function refreshForRequirementTraining(string $requirementId, string $trainingId): void
    {
        $this->broadcastRefreshed(
            $this->recalculate->handleForRequirementTraining($requirementId, $trainingId),
        );
    }

    /**
     * Recompute every TA affected by a StdFrequency change (repeat_days
     * edited or the frequency deleted) and broadcast the changed ones.
     */
    public function refreshForStdFrequency(string $orgId, string $stdFreqId): void
    {
        $this->broadcastRefreshed(
            $this->recalculate->handleForStdFrequency($orgId, $stdFreqId),
        );
    }

    /**
     * @param  Collection<int, TrainingAssignment>  $assignments
     */
    private function broadcastRefreshed($assignments): void
    {
        foreach ($assignments as $ta) {
            event(new TrainingAssignmentCreated($ta->load('activeSources'), actorId: Auth::id()));
        }
    }

    /**
     * "Assigned to you" inbox nudge. Self-actions are suppressed — the
     * actor just clicked Save; pinging them is noise.
     */
    private function notifyAssigned(
        string $userId,
        string $name,
        ?string $trainingId = null,
        ?string $requirementId = null,
    ): void {
        if (Auth::id() === $userId) {
            return;
        }

        User::query()
            ->withoutGlobalScope('organization')
            ->find($userId)
            ?->notify(new AssignmentCreatedForYou($name, $trainingId, $requirementId));
    }

    private function findOrCreate(string $orgId, string $userId, string $trainingId): TrainingAssignment
    {
        $training = Training::where('org_id', $orgId)->findOrFail($trainingId);

        return TrainingAssignment::firstOrCreate(
            ['user_id' => $userId, 'training_id' => $trainingId],
            ['org_id' => $orgId, 'name' => $training->name],
        );
    }

    /**
     * The subset of the requested ids that actually belong to this org, in one
     * query — replaces the bulk controller's per-user exists() check and
     * prevents cross-tenant writes.
     *
     * @param  array<int, string>  $userIds
     * @return Collection<int, string>
     */
    private function validUserIds(string $orgId, array $userIds): Collection
    {
        return User::where('org_id', $orgId)
            ->whereIn('id', $userIds)
            ->pluck('id');
    }

    /**
     * Create a TA with its status pre-materialized from the preloaded amber
     * window, so the model's `creating` hook skips its per-row
     * Organization::find (bda3b77). The batched recalc overwrites status with
     * the completion-derived bucket immediately after.
     */
    private function createAssignment(string $orgId, string $userId, Training $training, int $window): TrainingAssignment
    {
        $ta = new TrainingAssignment([
            'org_id' => $orgId,
            'user_id' => $userId,
            'training_id' => $training->id,
            'name' => $training->name,
        ]);

        $ta->status = (new TrainingStatusService)->statusFor($ta, $window);
        $ta->save();

        return $ta;
    }

    /**
     * Batched "assigned to you" nudge: one whereIn to hydrate every newly
     * assigned user (self-actions suppressed) rather than a find() per user.
     *
     * @param  array<int, string>  $userIds
     */
    private function notifyManyAssigned(
        array $userIds,
        string $name,
        ?string $trainingId = null,
        ?string $requirementId = null,
    ): void {
        $targets = array_values(array_filter($userIds, fn ($id) => $id !== Auth::id()));

        if ($targets === []) {
            return;
        }

        User::query()
            ->withoutGlobalScope('organization')
            ->whereIn('id', $targets)
            ->get()
            ->each(fn (User $u) => $u->notify(new AssignmentCreatedForYou($name, $trainingId, $requirementId)));
    }
}
