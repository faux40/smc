<?php

namespace Tests\Unit\Support;

use App\Models\CardStock;
use App\Support\Cards\CardSheetGeometry;
use PHPUnit\Framework\TestCase;

/**
 * Sheet geometry for printing cards onto purchased stock. Everything is in
 * points (1/72in) with the origin at the page's top-left, matching how the
 * imposition step will place merged card pages.
 */
class CardSheetGeometryTest extends TestCase
{
    /** Avery-5371-style 10-up wallet stock: 2 x 5 on US letter, no gutters. */
    private function wallet(array $overrides = []): CardSheetGeometry
    {
        return new CardSheetGeometry(new CardStock(array_merge([
            'page_width' => 612.0,   // 8.5in
            'page_height' => 792.0,  // 11in
            'column_count' => 2,
            'row_count' => 5,
            'card_width' => 243.0,   // 3.375in
            'card_height' => 153.0,  // 2.125in
            'margin_left' => 63.0,   // 0.875in
            'margin_top' => 27.0,    // 0.375in
            'gutter_x' => 0.0,
            'gutter_y' => 0.0,
        ], $overrides)));
    }

    public function test_cards_per_sheet_is_the_grid_size(): void
    {
        $this->assertSame(10, $this->wallet()->perSheet());
    }

    public function test_first_cell_sits_at_the_margins(): void
    {
        $this->assertSame(
            ['x' => 63.0, 'y' => 27.0, 'width' => 243.0, 'height' => 153.0],
            $this->wallet()->cellRect(0),
        );
    }

    public function test_cells_run_left_to_right_then_down(): void
    {
        $g = $this->wallet();

        // Index 1 is the second column of row 0.
        $this->assertSame(63.0 + 243.0, $g->cellRect(1)['x']);
        $this->assertSame(27.0, $g->cellRect(1)['y']);

        // Index 2 wraps to the first column of row 1.
        $this->assertSame(63.0, $g->cellRect(2)['x']);
        $this->assertSame(27.0 + 153.0, $g->cellRect(2)['y']);

        // Last cell: column 1, row 4.
        $this->assertSame(63.0 + 243.0, $g->cellRect(9)['x']);
        $this->assertSame(27.0 + 4 * 153.0, $g->cellRect(9)['y']);
    }

    public function test_gutters_push_later_rows_and_columns_out(): void
    {
        // A stock whose cards do not butt together — the spacing the user
        // tunes when a printed sheet drifts.
        $g = $this->wallet(['gutter_x' => 18.0, 'gutter_y' => 9.0]);

        $this->assertSame(63.0 + 243.0 + 18.0, $g->cellRect(1)['x']);
        $this->assertSame(27.0 + 153.0 + 9.0, $g->cellRect(2)['y']);
    }

    public function test_a_grid_that_fits_the_page_is_valid(): void
    {
        $this->assertTrue($this->wallet()->fits());
    }

    public function test_a_grid_wider_than_the_page_does_not_fit(): void
    {
        // 3 columns of 3.375in cards will not fit an 8.5in page at this margin.
        $this->assertFalse($this->wallet(['column_count' => 3])->fits());
    }

    public function test_a_grid_taller_than_the_page_does_not_fit(): void
    {
        $this->assertFalse($this->wallet(['row_count' => 6])->fits());
    }

    public function test_gutters_count_toward_the_fit(): void
    {
        // 2 x 5 fits exactly at 0 gutter; a 1in vertical gutter overflows.
        $this->assertFalse($this->wallet(['gutter_y' => 72.0])->fits());
    }

    public function test_an_out_of_range_cell_index_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->wallet()->cellRect(10);
    }
}
