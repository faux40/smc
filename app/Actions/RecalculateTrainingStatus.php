<?php

namespace App\Actions;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Services\TrainingStatusService;
use App\Support\RecalcContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RecalculateTrainingStatus
{
    /**
     * Recompute all assignments for an org. Returns the number of distinct
     * (user, training) pairs processed. Idempotent — safe to run repeatedly.
     *
     * The org's trainings, amber window, and requirement elements are loaded
     * once (RecalcContext) and the per-pair reads are batched, so cost stays a
     * fixed handful of queries plus one save per assignment — not the ~6
     * repeated lookups per pair a naive loop would do.
     *
     * @return array{processed: int}
     */
    public function handleAll(string $orgId): array
    {
        $pairs = TrainingAssignment::where('org_id', $orgId)
            ->select(['user_id', 'training_id'])
            ->distinct()
            ->get()
            ->map(fn ($p) => ['user_id' => $p->user_id, 'training_id' => $p->training_id]);

        if ($pairs->isEmpty()) {
            return ['processed' => 0];
        }

        $context = RecalcContext::forOrg($orgId, $pairs->pluck('training_id'));

        $this->handleMany($pairs, $context);

        return ['processed' => $pairs->count()];
    }

    /**
     * Recompute expires_at and last_completed_at on every TrainingAssignment
     * for the given (user, training) pair, based on the user's completion
     * history and the timing of each active source (J0.1: strictest wins).
     * Called by the CompletionObserver on every save/delete and by every
     * source add/remove.
     *
     * Returns the affected assignments so callers can broadcast the ones
     * whose dates actually changed (->wasChanged()).
     *
     * @return Collection<int, TrainingAssignment>
     */
    public function handle(string $userId, string $trainingId): Collection
    {
        $assignments = TrainingAssignment::where('user_id', $userId)
            ->where('training_id', $trainingId)
            ->with('activeSources')
            ->get();

        if ($assignments->isEmpty()) {
            return $assignments;
        }

        $latest = $this->latestCompletion($userId, $trainingId);
        $training = Training::with('stdFrequency')->find($trainingId);
        $window = Organization::find($assignments->first()->org_id)?->expiringSoonDays()
            ?? Organization::DEFAULT_EXPIRING_SOON_DAYS;

        $statusService = new TrainingStatusService;

        foreach ($assignments as $assignment) {
            $this->applyStatus($assignment, $latest, $training, $window, null, $statusService);
        }

        return $assignments;
    }

    /**
     * Batch variant of handle(): recompute a whole set of (user, training)
     * pairs using preloaded, loop-invariant context. All the assignments and
     * completion history for the pair set are read in two queries (rather than
     * two per pair), and the training/org/element lookups come from the
     * context, so total cost is a fixed constant plus one save per assignment.
     *
     * @param  Collection<int, array{user_id: string, training_id: string}>  $pairs
     * @return Collection<int, TrainingAssignment> the affected assignments
     */
    public function handleMany(Collection $pairs, RecalcContext $context): Collection
    {
        $pairs = $pairs
            ->unique(fn (array $p) => $p['user_id'].'|'.$p['training_id'])
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $wanted = $pairs->map(fn (array $p) => $p['user_id'].'|'.$p['training_id'])->flip();
        $userIds = $pairs->pluck('user_id')->unique()->values();
        $trainingIds = $pairs->pluck('training_id')->unique()->values();

        $assignments = TrainingAssignment::whereIn('user_id', $userIds)
            ->whereIn('training_id', $trainingIds)
            ->with('activeSources')
            ->get()
            ->filter(fn (TrainingAssignment $ta) => $wanted->has($ta->user_id.'|'.$ta->training_id))
            ->values();

        if ($assignments->isEmpty()) {
            return $assignments;
        }

        // Latest completion per (user, module) — ordered desc so the first row
        // in each group is the most recent.
        $completions = Completion::whereIn('user_id', $userIds)
            ->where('module_type', Training::class)
            ->whereIn('module_id', $trainingIds)
            ->orderByDesc('completion_date')
            ->get()
            ->groupBy(fn (Completion $c) => $c->user_id.'|'.$c->module_id);

        $statusService = new TrainingStatusService;

        foreach ($assignments as $assignment) {
            $latest = $completions->get($assignment->user_id.'|'.$assignment->training_id)?->first();
            $training = $context->trainings->get($assignment->training_id);

            $this->applyStatus($assignment, $latest, $training, $context->window, $context->elements, $statusService);
        }

        return $assignments;
    }

    /**
     * Recompute every TA in the org affected by a timing change on the given
     * requirement's training element (element edited or deleted).
     *
     * @return Collection<int, TrainingAssignment> assignments whose dates changed
     */
    public function handleForRequirementTraining(string $requirementId, string $trainingId): Collection
    {
        $pairs = TrainingAssignment::where('training_id', $trainingId)
            ->whereHas('activeSources', fn ($q) => $q
                ->where('sourceable_type', Requirement::class)
                ->where('sourceable_id', $requirementId))
            ->get(['user_id', 'training_id'])
            ->unique(fn ($ta) => $ta->user_id.'|'.$ta->training_id);

        return $this->recalcPairs($pairs);
    }

    /**
     * Recompute every TA in the org affected by a StdFrequency change
     * (repeat_days edited or the frequency deleted) — trainings using it as
     * template timing plus training-typed elements using it.
     *
     * @return Collection<int, TrainingAssignment> assignments whose dates changed
     */
    public function handleForStdFrequency(string $orgId, string $stdFreqId): Collection
    {
        $trainingIds = Training::where('org_id', $orgId)
            ->where('std_freq_id', $stdFreqId)
            ->pluck('id')
            ->merge(
                RqmtElement::where('org_id', $orgId)
                    ->where('std_freq_id', $stdFreqId)
                    ->where('module_type', Training::class)
                    ->pluck('module_id'),
            )
            ->unique();

        if ($trainingIds->isEmpty()) {
            return collect();
        }

        $pairs = TrainingAssignment::where('org_id', $orgId)
            ->whereIn('training_id', $trainingIds)
            ->get(['user_id', 'training_id'])
            ->unique(fn ($ta) => $ta->user_id.'|'.$ta->training_id);

        return $this->recalcPairs($pairs);
    }

    /**
     * @param  Collection<int, TrainingAssignment>  $pairs
     * @return Collection<int, TrainingAssignment> assignments whose dates changed
     */
    private function recalcPairs(Collection $pairs): Collection
    {
        return $pairs
            ->flatMap(fn ($pair) => $this->handle($pair->user_id, $pair->training_id))
            ->filter(fn (TrainingAssignment $ta) => $ta->wasChanged(['expires_at', 'last_completed_at', 'as_needed_only']))
            ->values();
    }

    /**
     * The single-assignment compute-and-save shared by handle() and
     * handleMany(). When $elements is non-null the requirement timing is read
     * from the preloaded map instead of querying per assignment.
     *
     * @param  Collection<string, RqmtElement>|null  $elements
     */
    private function applyStatus(
        TrainingAssignment $assignment,
        ?Completion $latest,
        ?Training $training,
        int $window,
        ?Collection $elements,
        TrainingStatusService $statusService,
    ): void {
        $timings = $this->sourceTimings($training, $assignment, $elements);
        [$expiresAt, $lastCompletedAt] = $this->computeStatus($latest, $timings);

        $assignment->expires_at = $expiresAt;
        $assignment->last_completed_at = $lastCompletedAt;
        // As-needed-only TAs are visible but never scheduled (J3).
        $assignment->as_needed_only = $timings->every(
            fn (array $t) => $t['as_needed'] && ! $t['repeating'] && ! $t['initial_only'],
        );
        // Materialize the bucket from the freshly-set columns (realtime half of
        // the denormalized status; the daily watchdog reconciles date-crossings).
        $assignment->status = $statusService->statusFor($assignment, $window);
        $assignment->save();
    }

    private function latestCompletion(string $userId, string $trainingId): ?Completion
    {
        return Completion::where('user_id', $userId)
            ->where('module_type', Training::class)
            ->where('module_id', $trainingId)
            ->orderByDesc('completion_date')
            ->first();
    }

    /**
     * @param  Collection<int, array{repeating: bool, repeat_days: int|null, initial_only: bool, as_needed: bool}>  $timings
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null}
     */
    private function computeStatus(?Completion $latest, Collection $timings): array
    {
        if ($latest === null) {
            return [null, null];
        }

        $lastCompletedAt = $latest->completion_date;

        // Explicit expiry on the completion record takes precedence.
        if ($latest->expire_date !== null) {
            return [$latest->expire_date, $lastCompletedAt];
        }

        // One candidate expiry per active source; the earliest wins because
        // satisfying the strictest source satisfies all of them (J0.1).
        // Sources whose timing is initial-only / as-needed / missing a
        // frequency contribute no expiry.
        $expiries = $timings
            ->map(fn (array $timing) => $timing['repeating'] && $timing['repeat_days'] !== null
                ? $lastCompletedAt->addDays($timing['repeat_days'])
                : null)
            ->filter();

        return [$expiries->min(), $lastCompletedAt];
    }

    /**
     * Resolve the timing of each active source: requirement sources use their
     * requirement's element for this training (per-case adjusted timing);
     * direct sources use the training template. A source whose element no
     * longer exists — and a TA with no sources at all — falls back to the
     * template so it keeps behaving like a direct assignment.
     *
     * @param  Collection<string, RqmtElement>|null  $preloadedElements  keyed "{requirement_id}|{training_id}"; when null, elements are queried
     * @return Collection<int, array{repeating: bool, repeat_days: int|null, initial_only: bool, as_needed: bool}>
     */
    private function sourceTimings(?Training $training, TrainingAssignment $assignment, ?Collection $preloadedElements = null): Collection
    {
        $template = [
            'repeating' => (bool) $training?->repeating,
            'repeat_days' => $training?->stdFrequency?->repeat_days,
            'initial_only' => (bool) $training?->initial_only,
            'as_needed' => (bool) $training?->as_needed,
        ];

        $sources = $assignment->activeSources;

        if ($sources->isEmpty()) {
            return collect([$template]);
        }

        $elements = $this->elementsFor($assignment, $sources, $preloadedElements);

        return $sources->map(function ($source) use ($template, $elements) {
            if ($source->sourceable_type !== Requirement::class) {
                return $template;
            }

            $element = $elements->get($source->sourceable_id);

            if ($element === null) {
                return $template;
            }

            return [
                'repeating' => $element->repeating,
                'repeat_days' => $element->stdFrequency?->repeat_days,
                'initial_only' => $element->initial_only,
                'as_needed' => $element->as_needed,
            ];
        });
    }

    /**
     * Requirement elements for this assignment's training, keyed by
     * requirement_id. Sourced from the preloaded map when present, otherwise
     * queried for just this assignment.
     *
     * @param  Collection<int, AssignmentSource>  $sources
     * @param  Collection<string, RqmtElement>|null  $preloadedElements
     * @return Collection<string, RqmtElement>
     */
    private function elementsFor(TrainingAssignment $assignment, Collection $sources, ?Collection $preloadedElements): Collection
    {
        $requirementIds = $sources
            ->where('sourceable_type', Requirement::class)
            ->pluck('sourceable_id');

        if ($preloadedElements !== null) {
            return $requirementIds
                ->mapWithKeys(fn ($reqId) => [
                    $reqId => $preloadedElements->get($reqId.'|'.$assignment->training_id),
                ])
                ->filter();
        }

        return RqmtElement::whereIn('requirement_id', $requirementIds)
            ->where('module_type', Training::class)
            ->where('module_id', $assignment->training_id)
            ->with('stdFrequency')
            ->get()
            ->keyBy('requirement_id');
    }
}
