<?php

namespace App\Support;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\RqmtElement;
use App\Models\Training;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * M1 — the one place completions are serialized for the UI (completions
 * index, user-detail history, dashboard recents, class credit lists).
 *
 * Enrichments beyond the raw row:
 *  - training_name: resolved from the module, trashed trainings included
 *    so old credit stays legible.
 *  - class_id / class_name: class-issued completions link back to their
 *    class via the class_training snapshot.
 *  - effective_element_ids: the pivot links PLUS every element credited
 *    by module identity (spec: a training completion counts wherever
 *    that training is required, assigned or not). The bare
 *    rqmt_element_ids stay as-entered for form prefill.
 */
class CompletionSerializer
{
    /**
     * @param  Collection<int, Completion>  $completions  rqmtElements eager-loaded
     * @return array<int, array<string, mixed>>
     */
    public static function collection(Collection $completions, bool $withPermissions = false): array
    {
        $trainingNames = Training::withTrashed()
            ->whereIn('id', $completions
                ->where('module_type', Training::class)
                ->pluck('module_id')
                ->unique())
            ->pluck('name', 'id');

        $classTrainings = ClassTraining::query()
            ->whereIn('id', $completions->pluck('class_training_id')->filter()->unique())
            ->with('trainingClass:id,name')
            ->get()
            ->keyBy('id');

        // Module-identity credits, grouped per "type|id" key. Only Training
        // modules exist today, but the grouping generalizes.
        $identityElements = RqmtElement::query()
            ->whereIn('module_id', $completions->pluck('module_id')->unique())
            ->get(['id', 'module_type', 'module_id'])
            ->groupBy(fn (RqmtElement $e) => $e->module_type.'|'.$e->module_id)
            ->map(fn (Collection $group) => $group->pluck('id')->all());

        return $completions->map(function (Completion $c) use ($trainingNames, $classTrainings, $identityElements, $withPermissions) {
            $sourceClass = $c->class_training_id !== null
                ? $classTrainings[$c->class_training_id]?->trainingClass
                : null;

            $pivotIds = $c->rqmtElements->pluck('id')->all();
            $identityIds = $identityElements[$c->module_type.'|'.$c->module_id] ?? [];

            $row = [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'module_type' => $c->module_type,
                'module_id' => $c->module_id,
                'training_name' => $c->module_type === Training::class
                    ? ($trainingNames[$c->module_id] ?? null)
                    : null,
                'completion_date' => $c->completion_date?->toDateString(),
                'certification_date' => $c->certification_date?->toDateString(),
                'expire_date' => $c->expire_date?->toDateString(),
                'cert_ident' => $c->cert_ident,
                'cert_id' => $c->cert_id,
                'hours' => $c->hours,
                'class_training_id' => $c->class_training_id,
                'class_id' => $sourceClass?->id,
                'class_name' => $sourceClass?->name,
                'notes' => $c->notes,
                'rqmt_element_ids' => $pivotIds,
                'effective_element_ids' => array_values(array_unique([...$pivotIds, ...$identityIds])),
            ];

            if ($withPermissions) {
                $row['can_edit'] = Gate::check('update', $c);
                $row['can_delete'] = Gate::check('delete', $c);
            }

            return $row;
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Completion $completion, bool $withPermissions = false): array
    {
        return self::collection(collect([$completion]), $withPermissions)[0];
    }
}
