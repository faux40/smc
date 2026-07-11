<?php

namespace Database\Factories;

use App\Models\MergeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MergeValue>
 */
class MergeValueFactory extends Factory
{
    protected $model = MergeValue::class;

    public function definition(): array
    {
        return [
            'location' => '',
            'department' => '',
            'value' => fake()->words(3, true),
        ];
    }
}
