<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\TrainingClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingClass>
 */
class TrainingClassFactory extends Factory
{
    protected $model = TrainingClass::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->words(3, true),
            'scheduled_date' => now()->toDateString(),
            'location' => null,
            'instructor' => null,
            'total_hours' => null,
            'notes' => null,
            'status' => 'scheduled',
            'completion_date' => null,
            'completed_at' => null,
        ];
    }
}
