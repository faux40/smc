<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'org_id' => Organization::factory(),
            'name' => fake()->unique()->word(),
            'color' => fake()->hexColor(),
        ];
    }
}
