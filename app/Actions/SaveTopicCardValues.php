<?php

namespace App\Actions;

use App\Http\Requests\ClassTopicUpdateRequest;
use App\Models\ClassTraining;
use App\Models\ClassTrainingCardValue;
use Illuminate\Support\Facades\DB;

/**
 * Record a class topic's answers for its training's custom card fields.
 *
 * A partial map is honoured: fields absent from it keep whatever they had.
 * An empty or null value is a hard delete, not a stored empty string — the
 * absence of a row is what "fall back to the training's default" means, so
 * storing '' would silently print blank where the default was expected.
 *
 * Validation (which fields belong to this topic, and the per-type length /
 * single-line rules) happens in {@see ClassTopicUpdateRequest}.
 */
class SaveTopicCardValues
{
    /**
     * @param  array<string, string|null>  $values  card_field_id => value
     */
    public function handle(ClassTraining $topic, array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($topic, $values): void {
            $orgId = $topic->trainingClass->org_id;

            foreach ($values as $fieldId => $value) {
                $value = is_string($value) ? $value : null;

                if ($value === null || $value === '') {
                    $topic->cardValues()->where('card_field_id', $fieldId)->delete();

                    continue;
                }

                ClassTrainingCardValue::updateOrCreate(
                    [
                        'class_training_id' => $topic->id,
                        'card_field_id' => $fieldId,
                    ],
                    [
                        'org_id' => $orgId,
                        'value' => $value,
                    ],
                );
            }
        });
    }
}
