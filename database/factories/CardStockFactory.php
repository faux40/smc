<?php

namespace Database\Factories;

use App\Models\CardStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardStock>
 */
class CardStockFactory extends Factory
{
    protected $model = CardStock::class;

    /**
     * A 10-up wallet-card sheet on US letter — the shape this feature exists
     * for. Points throughout.
     */
    public function definition(): array
    {
        return [
            'name' => 'Wallet cards 10-up '.fake()->unique()->numberBetween(1, 999999),
            'page_width' => 612.0,   // 8.5in
            'page_height' => 792.0,  // 11in
            'column_count' => 2,
            'row_count' => 5,
            'card_width' => 243.0,   // 3.375in
            'card_height' => 153.0,  // 2.125in
            'margin_top' => 27.0,    // 0.375in
            'margin_left' => 63.0,   // 0.875in
            'gutter_x' => 0.0,
            'gutter_y' => 0.0,
            'offset_x' => 0.0,
            'offset_y' => 0.0,
            'duplex_flip' => null,
            'notes' => null,
        ];
    }

    /** System scope: visible to every org, managed via console/seeder. */
    public function system(): static
    {
        return $this->state(fn () => ['org_id' => null]);
    }
}
