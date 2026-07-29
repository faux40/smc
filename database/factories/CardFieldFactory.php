<?php

namespace Database\Factories;

use App\Models\CardField;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardField>
 */
class CardFieldFactory extends Factory
{
    protected $model = CardField::class;

    public function definition(): array
    {
        return [
            'training_id' => Training::factory(),
            'key' => 'field_'.fake()->unique()->numberBetween(1, 999999),
            'label' => fake()->words(2, true),
            'type' => 'short',
            'default_value' => null,
            'seq' => 0,
        ];
    }

    /**
     * Inherit the training's org rather than minting a second one — a field
     * whose org disagrees with its training is not a state the app can reach.
     * Done after making so it works however the training was supplied
     * (`for($training)`, a nested factory, or a bare id).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CardField $field): void {
            $field->org_id ??= Training::withoutGlobalScope('organization')
                ->whereKey($field->training_id)
                ->value('org_id');
        });
    }

    /** A formatted field — markdown in, PPTX/ODP runs out (C5). */
    public function rich(): static
    {
        return $this->state(fn () => ['type' => 'rich']);
    }
}
