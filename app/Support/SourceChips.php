<?php

namespace App\Support;

use App\Models\AssignmentSource;
use App\Models\Requirement;
use App\Models\TrainingAssignment;
use Illuminate\Support\Collection;

/**
 * Serializes a TA's active sources into display chips:
 * `{type: 'direct'}` or `{type: 'requirement', id, name}`. Shared by the
 * user training-compliance payload (J3) and the dashboard needs-action
 * rows (K2) so the chips render identically everywhere.
 */
class SourceChips
{
    /**
     * Requirement-name lookup covering every active source across $tas —
     * one query, keyed by requirement id.
     *
     * @param  Collection<int, TrainingAssignment>  $tas
     * @return Collection<string, string>
     */
    public static function names(Collection $tas): Collection
    {
        return Requirement::query()
            ->whereIn('id', $tas
                ->flatMap(fn (TrainingAssignment $ta) => $ta->activeSources->pluck('sourceable_id'))
                ->filter()
                ->unique())
            ->pluck('name', 'id');
    }

    /**
     * @param  Collection<string, string>  $names  output of names()
     * @return array<int, array{type: string, id: string|null, name: string|null}>
     */
    public static function for(TrainingAssignment $ta, Collection $names): array
    {
        return $ta->activeSources
            ->map(fn (AssignmentSource $s) => $s->sourceable_type === Requirement::class
                ? ['type' => 'requirement', 'id' => $s->sourceable_id, 'name' => $names[$s->sourceable_id] ?? null]
                : ['type' => 'direct', 'id' => null, 'name' => null])
            ->values()
            ->all();
    }
}
