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
use App\Support\TrainingLadder;
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
    public function handle(string $userId, string $trainingId, ?TrainingLadder $ladder = null): Collection
    {
        $assignments = TrainingAssignment::where('user_id', $userId)
            ->where('training_id', $trainingId)
            ->with('activeSources')
            ->get();

        if ($assignments->isEmpty()) {
            return $assignments;
        }

        $orgId = $assignments->first()->org_id;
        $latest = $this->latestCompletion($userId, $trainingId);
        $training = Training::with('stdFrequency')->find($trainingId);
        $window = Organization::find($orgId)?->expiringSoonDays()
            ?? Organization::DEFAULT_EXPIRING_SOON_DAYS;

        $ladder ??= TrainingLadder::forOrg($orgId);
        $ancestors = $ladder->ancestorsOf($trainingId);
        $latestByAncestor = $this->ancestorCompletions($userId, $ancestors);
        $covering = $this->coveringCandidates(
            $ancestors,
            fn (string $ancestorId) => $latestByAncestor->get($ancestorId)?->first(),
        );

        $statusService = new TrainingStatusService;

        foreach ($assignments as $assignment) {
            $this->applyStatus($assignment, $latest, $training, $window, null, $statusService, $covering);
        }

        return $assignments;
    }

    /**
     * handle() plus the fan-down: a completion on training H changes the
     * status of every training H (transitively) covers, so those pairs are
     * recomputed in the same breath. The CompletionObserver calls this —
     * without it, completing Competent leaves the person's Authorized row
     * stale until the nightly watchdog.
     *
     * @return Collection<int, TrainingAssignment>
     */
    public function handleWithDescendants(string $userId, string $trainingId, string $orgId): Collection
    {
        $ladder = TrainingLadder::forOrg($orgId);

        $affected = $this->handle($userId, $trainingId, $ladder);

        foreach ($ladder->descendantsOf($trainingId) as $descendantId) {
            $affected = $affected->merge($this->handle($userId, $descendantId, $ladder));
        }

        return $affected;
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

        // Every training that could contribute a credit: the pair set's own
        // plus everything above them in the hierarchy — a covering
        // credential satisfies from outside the pair set.
        $ancestorsByTraining = $trainingIds->mapWithKeys(
            fn (string $id) => [$id => $context->ladder->ancestorsOf($id)],
        );
        $moduleIds = $trainingIds
            ->merge($ancestorsByTraining->flatMap(fn (Collection $a) => $a->pluck('id')))
            ->unique()
            ->values();

        // Latest completion per (user, module) — ordered desc so the first row
        // in each group is the most recent.
        $completions = Completion::whereIn('user_id', $userIds)
            ->where('module_type', Training::class)
            ->whereIn('module_id', $moduleIds)
            ->orderByDesc('completion_date')
            ->get()
            ->groupBy(fn (Completion $c) => $c->user_id.'|'.$c->module_id);

        $statusService = new TrainingStatusService;

        foreach ($assignments as $assignment) {
            $latest = $completions->get($assignment->user_id.'|'.$assignment->training_id)?->first();
            $training = $context->trainings->get($assignment->training_id);
            $covering = $this->coveringCandidates(
                $ancestorsByTraining->get($assignment->training_id) ?? collect(),
                fn (string $ancestorId) => $completions
                    ->get($assignment->user_id.'|'.$ancestorId)?->first(),
            );

            $this->applyStatus($assignment, $latest, $training, $context->window, $context->elements, $statusService, $covering);
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
     * $covering holds one candidate per ancestor training that has a
     * completion — the hierarchy's contribution. The best effective expiry
     * wins, the training's own completion on ties, and the winner's identity
     * lands in satisfied_via_training_id (null = satisfied itself).
     *
     * @param  Collection<string, RqmtElement>|null  $elements
     * @param  Collection<int, array{completion: Completion, expiry: CarbonInterface|null, via: string}>  $covering
     */
    private function applyStatus(
        TrainingAssignment $assignment,
        ?Completion $latest,
        ?Training $training,
        int $window,
        ?Collection $elements,
        TrainingStatusService $statusService,
        ?Collection $covering = null,
    ): void {
        $timings = $this->sourceTimings($training, $assignment, $elements);
        [$expiresAt, $lastCompletedAt] = $this->computeStatus($latest, $timings);

        $best = ['completion' => $latest, 'expiry' => $expiresAt, 'via' => null];

        foreach ($covering ?? collect() as $candidate) {
            if ($this->outlasts($candidate['expiry'], $best)) {
                $best = $candidate;
                $lastCompletedAt = $candidate['completion']->completion_date;
            }
        }

        $assignment->expires_at = $best['expiry'];
        $assignment->last_completed_at = $best['completion']?->completion_date;
        $assignment->satisfied_via_training_id = $best['via'];
        // As-needed-only TAs are visible but never scheduled (J3).
        $assignment->as_needed_only = $timings->every(
            fn (array $t) => $t['as_needed'] && ! $t['repeating'] && ! $t['initial_only'],
        );
        // Materialize the bucket from the freshly-set columns (realtime half of
        // the denormalized status; the daily watchdog reconciles date-crossings).
        $assignment->status = $statusService->statusFor($assignment, $window);
        $assignment->save();
    }

    /**
     * Does a covering candidate beat the current best credit?
     *
     * Strictly-better only: on equal expiries the training's own completion
     * keeps satisfied_via null, which is the honest reading. A null expiry
     * WITH a completion means "never expires" and beats any date; no
     * completion at all loses to anything.
     *
     * @param  array{completion: Completion|null, expiry: CarbonInterface|null, via: string|null}  $best
     */
    private function outlasts(?CarbonInterface $candidateExpiry, array $best): bool
    {
        if ($best['completion'] === null) {
            return true;
        }

        if ($best['expiry'] === null) {
            return false; // current best never expires
        }

        if ($candidateExpiry === null) {
            return true; // candidate never expires, best does
        }

        return $candidateExpiry->gt($best['expiry']);
    }

    /**
     * One candidate per covering training the user holds a completion for.
     * The expiry is the credential's own: its explicit expire_date, else its
     * completion date pushed out by ITS training's cycle — never re-derived
     * from the lower training's timing (the credential carries).
     *
     * @param  Collection<int, Training>  $ancestors
     * @param  callable(string): ?Completion  $latestFor
     * @return Collection<int, array{completion: Completion, expiry: CarbonInterface|null, via: string}>
     */
    private function coveringCandidates(Collection $ancestors, callable $latestFor): Collection
    {
        return $ancestors
            ->map(function (Training $ancestor) use ($latestFor) {
                $completion = $latestFor($ancestor->id);

                if ($completion === null) {
                    return null;
                }

                return [
                    'completion' => $completion,
                    'expiry' => $this->credentialExpiry($completion, $ancestor),
                    'via' => $ancestor->id,
                ];
            })
            ->filter()
            ->values();
    }

    private function credentialExpiry(Completion $completion, Training $training): ?CarbonInterface
    {
        if ($completion->expire_date !== null) {
            return $completion->expire_date;
        }

        return $training->repeating && $training->stdFrequency?->repeat_days !== null
            ? $completion->completion_date->addDays($training->stdFrequency->repeat_days)
            : null;
    }

    /**
     * The user's completions of the given ancestor trainings, newest first,
     * keyed by training id. One query for the whole chain.
     *
     * @param  Collection<int, Training>  $ancestors
     * @return Collection<string, Collection<int, Completion>>
     */
    private function ancestorCompletions(string $userId, Collection $ancestors): Collection
    {
        if ($ancestors->isEmpty()) {
            return collect();
        }

        return Completion::where('user_id', $userId)
            ->where('module_type', Training::class)
            ->whereIn('module_id', $ancestors->pluck('id'))
            ->orderByDesc('completion_date')
            ->get()
            ->groupBy('module_id');
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
