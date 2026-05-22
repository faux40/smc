<?php

namespace Database\Factories;

use App\Models\ClassEnrollment;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassEnrollment>
 */
class ClassEnrollmentFactory extends Factory
{
    protected $model = ClassEnrollment::class;

    public function definition(): array
    {
        return [
            'class_id' => TrainingClass::factory(),
            'user_id' => User::factory(),
            'status' => 'enrolled',
            'notes' => null,
        ];
    }
}
