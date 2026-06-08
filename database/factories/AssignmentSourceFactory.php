<?php

namespace Database\Factories;

use App\Models\AssignmentSource;
use App\Models\TrainingAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentSource>
 */
class AssignmentSourceFactory extends Factory
{
    protected $model = AssignmentSource::class;

    public function definition(): array
    {
        return [
            'training_assignment_id' => TrainingAssignment::factory(),
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
            'removed_at' => null,
        ];
    }

    public function forRequirement(\App\Models\Requirement $requirement): static
    {
        return $this->state([
            'sourceable_type' => \App\Models\Requirement::class,
            'sourceable_id' => $requirement->id,
        ]);
    }

    public function removed(): static
    {
        return $this->state(['removed_at' => now()]);
    }
}
