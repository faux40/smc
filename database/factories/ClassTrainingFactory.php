<?php

namespace Database\Factories;

use App\Models\ClassTraining;
use App\Models\Training;
use App\Models\TrainingClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassTraining>
 */
class ClassTrainingFactory extends Factory
{
    protected $model = ClassTraining::class;

    public function definition(): array
    {
        return [
            'class_id' => TrainingClass::factory(),
            'training_id' => Training::factory(),
            'training_name' => fake()->words(3, true),
            'initial_only' => false,
            'repeating' => true,
            'as_needed' => false,
            'repeat_days' => 365,
            'std_freq_name' => 'Annual',
            'hours' => null,
            'expire_date' => null,
        ];
    }
}
