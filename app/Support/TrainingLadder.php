<?php

namespace App\Support;

use App\Models\Training;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An org's training hierarchy, walked in memory.
 *
 * `training_satisfiers` edges point UP: a lower training names the higher
 * ones whose credentials satisfy it — ANY of them (OR-semantics), so the
 * graph is a DAG, not a chain: diamonds are legal, cycles are refused at the
 * write side. This loads the org's entire edge list once (tens of rows) so
 * ancestor/descendant walks cost nothing per pair — the recalc is the only
 * consumer, and every read-side surface just sees the materialized result.
 *
 * Soft-deleted trainings are loaded on purpose, twice over: the walk hops
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
     * @param  array<string, list<string>>  $parents  child id → parent ids (upward edges)
     */
    private function __construct(
        public readonly Collection $trainings,
        private readonly array $parents,
    ) {}

    public static function forOrg(string $orgId): self
    {
        $trainings = Training::withTrashed()
            ->with('stdFrequency')
            ->where('org_id', $orgId)
            ->get()
            ->keyBy('id');

        $parents = DB::table('training_satisfiers')
            ->where('org_id', $orgId)
            ->get(['training_id', 'satisfied_by_id'])
            ->groupBy('training_id')
            ->map(fn ($edges) => $edges->pluck('satisfied_by_id')->all())
            ->all();

        return new self($trainings, $parents);
    }

    /**
     * The trainings whose credentials satisfy $trainingId — every transitive
     * parent across every branch, nearest level first (BFS).
     *
     * @return Collection<int, Training>
     */
    public function ancestorsOf(string $trainingId): Collection
    {
        $ancestors = collect();
        $seen = [$trainingId => true];
        $frontier = [$trainingId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                foreach ($this->parents[$id] ?? [] as $parentId) {
                    if (isset($seen[$parentId])) {
                        continue;
                    }

                    $seen[$parentId] = true;
                    $parent = $this->trainings->get($parentId);

                    if ($parent === null) {
                        continue;
                    }

                    $ancestors->push($parent);
                    $next[] = $parentId;
                }
            }

            $frontier = $next;
        }

        return $ancestors;
    }

    /**
     * The training ids that $trainingId's credential (transitively) satisfies
     * — everything below it across every branch. This is the fan-down set: a
     * completion on $trainingId must recalculate each of these for that user.
     *
     * @return Collection<int, string>
     */
    public function descendantsOf(string $trainingId): Collection
    {
        // Upward edges inverted once per call; the table is tiny.
        $children = [];
        foreach ($this->parents as $childId => $parentIds) {
            foreach ($parentIds as $parentId) {
                $children[$parentId][] = $childId;
            }
        }

        $found = collect();
        $seen = [$trainingId => true];
        $frontier = [$trainingId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $id) {
                foreach ($children[$id] ?? [] as $childId) {
                    if (isset($seen[$childId])) {
                        continue;
                    }

                    $seen[$childId] = true;
                    $found->push($childId);
                    $next[] = $childId;
                }
            }

            $frontier = $next;
        }

        return $found;
    }
}
