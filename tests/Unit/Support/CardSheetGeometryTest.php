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

    // ---- calibration offsets (C6a) --------------------------------------

    public function test_offsets_nudge_every_cell_by_the_same_amount(): void
    {
        /*
         * The whole-sheet correction for a printer that lands the image a
         * little off where the paper actually is. Applied here, in the one
         * place the grid is computed, so the imposer's fronts AND backs and
         * every preview inherit it without knowing it exists.
         */
        $g = $this->wallet(['offset_x' => 4.5, 'offset_y' => -3.0]);

        $this->assertSame(63.0 + 4.5, $g->cellRect(0)['x']);
        $this->assertSame(27.0 - 3.0, $g->cellRect(0)['y']);
        // The last cell moves by exactly the same amount — a shift, not a
        // scale or a gutter change.
        $this->assertSame(63.0 + 243.0 + 4.5, $g->cellRect(9)['x']);
        $this->assertSame(27.0 + 4 * 153.0 - 3.0, $g->cellRect(9)['y']);
    }

    public function test_offsets_leave_the_cell_size_alone(): void
    {
        $rect = $this->wallet(['offset_x' => 4.5, 'offset_y' => -3.0])->cellRect(0);

        $this->assertSame(243.0, $rect['width']);
        $this->assertSame(153.0, $rect['height']);
    }

    public function test_a_realistic_offset_still_fits(): void
    {
        // ±1mm of drift — the normal case. Y goes up rather than down
        // because this stock's grid ends exactly at the page bottom
        // (27 + 5 x 153 = 792): true to life, full-page stocks have no
        // slack in one direction and the drift has to fit the margins.
        $this->assertTrue(
            $this->wallet(['offset_x' => 2.83, 'offset_y' => -2.83])->fits(),
        );
    }

    public function test_an_offset_that_shoves_the_grid_off_the_right_edge_fails_the_fit(): void
    {
        // Used width is 549pt on a 612pt page — 63pt of room. One point
        // more than that and the right column clips.
        $this->assertFalse($this->wallet(['offset_x' => 64.0])->fits());
    }

    public function test_any_downward_nudge_overflows_a_grid_that_ends_at_the_page_edge(): void
    {
        // The same full-height grid: the bottom row already touches the
        // page edge, so even a millimetre down is a clipped card.
        $this->assertFalse($this->wallet(['offset_y' => 2.83])->fits());
    }

    public function test_an_offset_that_shoves_the_grid_off_the_top_edge_fails_the_fit(): void
    {
        // The top margin is 0.375in; nudging up by half an inch clips row 0.
        // Cards clipped at the page edge are exactly the waste of purchased
        // stock this feature exists to prevent, so the fit says no.
        $this->assertFalse($this->wallet(['offset_y' => -36.0])->fits());
    }

    public function test_a_negative_offset_within_the_margin_is_fine(): void
    {
        $this->assertTrue($this->wallet(['offset_y' => -18.0])->fits());
    }
}
