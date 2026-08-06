<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\RqmtElement;
use App\Models\Training;
use Illuminate\Support\Collection;

/**
 * Preloaded, loop-invariant inputs for a batch RecalculateTrainingStatus run.
 *
 * A naive recalc re-fetches the same training (+stdFrequency), the org's amber
 * window, and the requirement elements on every (user, training) iteration —
 * identical every time. Hoisting them here turns O(pairs) duplicate lookups
 * into a fixed handful, so bulk assignment and the org-wide resync stay
 * sub-linear in query count.
 */
final class RecalcContext
{
    /**
     * @param  Collection<string, Training>  $trainings  keyed by training id, stdFrequency eager-loaded
     * @param  int  $window  the org's expiring-soon (amber) window in days
     * @param  Collection<string, RqmtElement>  $elements  Training-typed requirement elements keyed by "{requirement_id}|{module_id}", stdFrequency eager-loaded
     * @param  TrainingLadder  $ladder  the org's hierarchy edges, for coverage resolution
     */
    public function __construct(
        public readonly Collection $trainings,
        public readonly int $window,
        public readonly Collection $elements,
        public readonly TrainingLadder $ladder,
    ) {}

    /**
     * Build a context from explicit trainings/elements (bulk-assign path,
     * where the caller already holds the requirement + its elements).
     *
     * @param  Collection<int, Training>  $trainings
     * @param  Collection<int, RqmtElement>|null  $elements
     */
    public static function make(string $orgId, Collection $trainings, ?Collection $elements = null): self
    {
        return new self(
            trainings: $trainings->keyBy('id'),
            window: self::windowFor($orgId),
            elements: self::keyElements($elements ?? collect()),
            ladder: TrainingLadder::forOrg($orgId),
        );
    }

    /**
     * Build a context covering every training referenced by the given ids plus
     * all of the org's Training-typed requirement elements (org-wide resync).
     *
     * @param  Collection<int, string>  $trainingIds
     */
    public static function forOrg(string $orgId, Collection $trainingIds): self
    {
        $trainings = Training::with('stdFrequency')
            ->whereIn('id', $trainingIds->unique()->values())
            ->get();

        $elements = RqmtElement::where('org_id', $orgId)
            ->where('module_type', Training::class)
            ->with('stdFrequency')
            ->get();

        return new self(
            trainings: $trainings->keyBy('id'),
            window: self::windowFor($orgId),
            elements: self::keyElements($elements),
            ladder: TrainingLadder::forOrg($orgId),
        );
    }

    private static function windowFor(string $orgId): int
    {
        return Organization::find($orgId)?->expiringSoonDays()
            ?? Organization::DEFAULT_EXPIRING_SOON_DAYS;
    }

    /**
     * @param  Collection<int, RqmtElement>  $elements
     * @return Collection<string, RqmtElement>
     */
    private static function keyElements(Collection $elements): Collection
    {
        return $elements->keyBy(fn (RqmtElement $e) => $e->requirement_id.'|'.$e->module_id);
    }
}
