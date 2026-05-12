<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => fn (array $attrs) => User::factory()->create(['org_id' => $attrs['org_id']])->id,
            'requirement_id' => fn (array $attrs) => Requirement::factory()->create(['org_id' => $attrs['org_id']])->id,
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => null,
            'as_needed' => false,
            'name' => fake()->words(3, true),
            'description' => null,
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ];
    }
}
