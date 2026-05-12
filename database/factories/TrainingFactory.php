<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Training>
 */
class TrainingFactory extends Factory
{
    protected $model = Training::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            // Default to repeating annually — the most common template.
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => null,
            'as_needed' => false,
        ];
    }
}
