<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\StdFrequency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StdFrequency>
 */
class StdFrequencyFactory extends Factory
{
    protected $model = StdFrequency::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->randomElement(['Annual', 'Bi-Annual', '10 Working Days', 'Quarterly']),
            'repeat_days' => fake()->randomElement([14, 90, 180, 365]),
        ];
    }
}
