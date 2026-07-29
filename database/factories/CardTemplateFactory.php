<?php

namespace Database\Factories;

use App\Models\CardTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardTemplate>
 */
class CardTemplateFactory extends Factory
{
    protected $model = CardTemplate::class;

    /** A single-sided 3.375 x 2.125in wallet card, in points. */
    public function definition(): array
    {
        return [
            'name' => 'Wallet card '.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'original_filename' => 'card.pptx',
            'extension' => 'pptx',
            'path' => 'card-templates/'.fake()->uuid().'.pptx',
            'size' => 4096,
            'placeholders' => ['user_name'],
            'fonts' => ['Arial'],
            'unsupported_fonts' => [],
            'slide_count' => 1,
            'card_width' => 243.0,
            'card_height' => 153.0,
            'version' => 1,
        ];
    }

    /** System scope: visible to every org, managed via console/seeder. */
    public function system(): static
    {
        return $this->state(fn () => ['org_id' => null]);
    }
}
