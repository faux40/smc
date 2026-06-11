<?php

namespace App\Actions;

use App\Models\Completion;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RecalculateTrainingStatus
{
    /**
     * Recompute all assignments for an org. Returns the number of distinct
     * (user, training) pairs processed. Idempotent — safe to run repeatedly.
     *
     * @return array{processed: int}
     */
    public function handleAll(string $orgId): array
    {
        $pairs = TrainingAssignment::where('org_id', $orgId)
            ->select(['user_id', 'training_id'])
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $this->handle($pair->user_id, $pair->training_id);
        }

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

        $latest = Completion::where('user_id', $userId)
            ->where('module_type', Training::class)
            ->where('module_id', $trainingId)
            ->orderByDesc('completion_date')
            ->first();

        $training = Training::with('stdFrequency')->find($trainingId);

        foreach ($assignments as $assignment) {
            $timings = $this->sourceTimings($training, $assignment);
            [$expiresAt, $lastCompletedAt] = $this->computeStatus($latest, $timings);

            $assignment->update([
                'expires_at' => $expiresAt,
                'last_completed_at' => $lastCompletedAt,
                // As-needed-only TAs are visible but never scheduled (J3).
                'as_needed_only' => $timings->every(
                    fn (array $t) => $t['as_needed'] && ! $t['repeating'] && ! $t['initial_only'],
                ),
            ]);
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
     * @return Collection<int, array{repeating: bool, repeat_days: int|null, initial_only: bool, as_needed: bool}>
     */
    private function sourceTimings(?Training $training, TrainingAssignment $assignment): Collection
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

        $requirementIds = $sources
            ->where('sourceable_type', Requirement::class)
            ->pluck('sourceable_id');

        $elements = RqmtElement::whereIn('requirement_id', $requirementIds)
            ->where('module_type', Training::class)
            ->where('module_id', $assignment->training_id)
            ->with('stdFrequency')
            ->get()
            ->keyBy('requirement_id');

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
}
