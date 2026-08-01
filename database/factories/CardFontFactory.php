<?php

namespace Database\Factories;

use App\Models\CardFont;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardFont>
 */
class CardFontFactory extends Factory
{
    protected $model = CardFont::class;

    public function definition(): array
    {
        $family = $this->faker->unique()->words(2, true);

        return [
            'org_id' => Organization::factory(),
            'family' => Str::title($family),
            'original_filename' => Str::slug($family).'.ttf',
            'format' => 'ttf',
            'path' => 'card-fonts/'.Str::uuid().'.ttf',
            'size' => $this->faker->numberBetween(20_000, 400_000),
        ];
    }
}
