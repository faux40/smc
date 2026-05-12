<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RqmtElement>
 */
class RqmtElementFactory extends Factory
{
    protected $model = RqmtElement::class;

    public function definition(): array
    {
        // Default to a Training-typed element; callers can override.
        return [
            'org_id' => Organization::factory(),
            'requirement_id' => Requirement::factory(),
            'module_type' => Training::class,
            'module_id' => fn (array $attrs) => Training::factory()->create([
                'org_id' => $attrs['org_id'],
            ])->id,
            'name' => fake()->words(3, true),
            'description' => null,
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => null,
            'as_needed' => false,
        ];
    }
}
