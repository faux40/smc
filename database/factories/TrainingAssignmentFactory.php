<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingAssignment>
 */
class TrainingAssignmentFactory extends Factory
{
    protected $model = TrainingAssignment::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'user_id' => fn (array $attrs) => User::factory()->create(['org_id' => $attrs['org_id']])->id,
            'training_id' => fn (array $attrs) => Training::factory()->create(['org_id' => $attrs['org_id']])->id,
            'name' => fn (array $attrs) => Training::find($attrs['training_id'])?->name ?? fake()->words(3, true),
            'expires_at' => null,
            'last_completed_at' => null,
        ];
    }
}
