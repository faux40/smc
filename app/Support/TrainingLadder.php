<?php

namespace App\Support;

use App\Models\Training;
use Illuminate\Support\Collection;

/**
 * An org's training hierarchy, walked in memory.
 *
 * `trainings.superseded_by_id` points UP: a lower training names the higher
 * one whose credential satisfies it. This loads the org's entire edge list
 * once (tens of rows) so ancestor/descendant walks cost nothing per pair —
 * the recalc is the only consumer, and every read-side surface just sees the
 * materialized result.
 *
 * Soft-deleted trainings are loaded on purpose, twice over: the chain hops
 * through a deleted node (deleting Competent must not sever Qualified from
 * Authorized), and a deleted training's completions still count until they
 * expire — removing a training from the library doesn't un-train anyone.
 *
 * Walks are cycle-safe by visited-set. Writes refuse cycles, but a resolver
 * that can hang on bad data is a resolver that will eventually hang.
 */
final class TrainingLadder
{
    /**
     * @param  Collection<string, Training>  $trainings  keyed by id, withTrashed, stdFrequency loaded
     */
    private function __construct(
        public readonly Collection $trainings,
    ) {}

    public static function forOrg(string $orgId): self
    {
        return new self(
            Training::withTrashed()
                ->with('stdFrequency')
                ->where('org_id', $orgId)
                ->get()
                ->keyBy('id'),
        );
    }

    /**
     * The trainings whose credentials satisfy $trainingId, nearest first.
     *
     * @return Collection<int, Training>
     */
    public function ancestorsOf(string $trainingId): Collection
    {
        $ancestors = collect();
        $seen = [$trainingId => true];
        $current = $this->trainings->get($trainingId);

        while (($nextId = $current?->superseded_by_id) !== null) {
            if (isset($seen[$nextId])) {
                break;
            }

            $seen[$nextId] = true;
            $current = $this->trainings->get($nextId);

            if ($current === null) {
                break;
            }

            $ancestors->push($current);
        }

        return $ancestors;
    }

    /**
     * The training ids that $trainingId's credential (transitively) satisfies
     * — everything below it. This is the fan-down set: a completion on
     * $trainingId must recalculate each of these for that user.
     *
     * @return Collection<int, string>
     */
    public function descendantsOf(string $trainingId): Collection
    {
        // Child → parent edges inverted once per call; the table is tiny.
        $children = $this->trainings
            ->filter(fn (Training $t) => $t->superseded_by_id !== null)
            ->groupBy('superseded_by_id');

        $found = collect();
        $seen = [$trainingId => true];
        $frontier = [$trainingId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                foreach ($children->get($id, collect()) as $child) {
                    if (isset($seen[$child->id])) {
                        continue;
                    }

                    $seen[$child->id] = true;
                    $found->push($child->id);
                    $next[] = $child->id;
                }
            }

            $frontier = $next;
        }

        return $found;
    }
}
