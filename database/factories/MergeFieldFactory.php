<?php

namespace Database\Factories;

use App\Models\MergeField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MergeField>
 */
class MergeFieldFactory extends Factory
{
    protected $model = MergeField::class;

    public function definition(): array
    {
        return [
            'key' => 'field_'.fake()->unique()->numberBetween(1, 999999),
            'label' => fake()->words(2, true),
            'type' => 'text',
            'field_group' => null,
            'help' => null,
            'seq' => 0,
            'draft' => false,
        ];
    }

    /** System scope: visible to every org, managed via console/seeder. */
    public function system(): static
    {
        return $this->state(fn () => ['org_id' => null]);
    }
}
