<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Requirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requirement>
 */
class RequirementFactory extends Factory
{
    protected $model = Requirement::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
        ];
    }
}
