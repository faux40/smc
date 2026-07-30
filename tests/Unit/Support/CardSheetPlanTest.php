<?php

namespace Tests\Unit\Support;

use App\Models\CardStock;
use App\Support\Cards\CardSheetPlan;
use PHPUnit\Framework\TestCase;

/**
 * Where every card lands (custom-certs C4b): which sheet, which cell, and at
 * which point on the page — including the start-cell offset for a partial
 * sheet already run through the printer, and the mirroring that makes a
 * manually flipped stack land the right back on the right front.
 *
 * All of the decisions live here as arithmetic, so the FPDI step downstream is
 * a dumb executor. Points throughout, origin top-left.
 */
class CardSheetPlanTest extends TestCase
{
    /** Avery-5371-style 10-up wallet stock: 2 x 5 on US letter, no gutters. */
    private function wallet(array $overrides = []): CardStock
    {
        return new CardStock(array_merge([
            'page_width' => 612.0,
            'page_height' => 792.0,
            'column_count' => 2,
            'row_count' => 5,
            'card_width' => 243.0,
            'card_height' => 153.0,
            'margin_left' => 63.0,
            'margin_top' => 27.0,
            'gutter_x' => 0.0,
            'gutter_y' => 0.0,
            'duplex_flip' => 'long_edge',
        ], $overrides));
    }

    /** A plan whose card is exactly the stock's cell size. */
    private function plan(array $stock = [], ?float $w = null, ?float $h = null): CardSheetPlan
    {
        $card = $this->wallet($stock);

        return new CardSheetPlan($card, $w ?? 243.0, $h ?? 153.0);
    }

    // ---- sheets -----------------------------------------------------------

    public function test_a_full_sheet_of_cards_is_one_sheet(): void
    {
        $plan = $this->plan();

        $this->assertSame(10, $plan->perSheet());
        $this->assertSame(1, $plan->sheetCount(10));
        $this->assertCount(1, $plan->fronts(10));
        $this->assertCount(10, $plan->fronts(10)[0]);
    }

    public function test_one_card_past_a_sheet_starts_another(): void
    {
        $plan = $this->plan();

        $this->assertSame(2, $plan->sheetCount(11));
        $this->assertCount(1, $plan->fronts(11)[1]);
    }

    public function test_twenty_three_cards_fill_three_sheets(): void
    {
        $pages = $this->plan()->fronts(23);

        $this->assertSame([10, 10, 3], array_map('count', $pages));
        // Card indexes run straight through the sheets in order.
        $this->assertSame(0, $pages[0][0]['card']);
        $this->assertSame(10, $pages[1][0]['card']);
        $this->assertSame(22, $pages[2][2]['card']);
    }

    public function test_no_cards_means_no_sheets(): void
    {
        $this->assertSame(0, $this->plan()->sheetCount(0));
        $this->assertSame([], $this->plan()->fronts(0));
    }

    // ---- start cell (partial sheet) ---------------------------------------

    public function test_starting_at_cell_four_leaves_the_first_three_cells_empty(): void
    {
        // The sheet came out of the printer with three cards already used.
        $pages = $this->plan()->fronts(7, 4);

        $this->assertCount(1, $pages);
        $this->assertCount(7, $pages[0]);
        $this->assertSame(3, $pages[0][0]['cell']);
        $this->assertSame(9, $pages[0][6]['cell']);
    }

    public function test_the_offset_applies_only_to_the_first_sheet(): void
    {
        $pages = $this->plan()->fronts(12, 4);

        $this->assertSame([7, 5], array_map('count', $pages));
        // Second sheet is fresh stock — back to cell 0.
        $this->assertSame(0, $pages[1][0]['cell']);
    }

    public function test_the_start_cell_counts_toward_the_sheet_total(): void
    {
        $this->assertSame(2, $this->plan()->sheetCount(8, 4));
    }

    public function test_a_start_cell_off_the_sheet_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->plan()->fronts(1, 11);
    }

    public function test_a_start_cell_below_one_is_rejected(): void
    {
        // The UI counts cells from 1; 0 would silently mean "cell -1".
        $this->expectException(\InvalidArgumentException::class);
        $this->plan()->fronts(1, 0);
    }

    // ---- placement --------------------------------------------------------

    public function test_a_card_the_size_of_its_cell_sits_at_the_cell_corner(): void
    {
        $first = $this->plan()->fronts(1)[0][0];

        $this->assertSame(63.0, $first['x']);
        $this->assertSame(27.0, $first['y']);
    }

    public function test_a_smaller_card_is_centred_in_its_cell(): void
    {
        // Confirmed behaviour: a near-miss between the design and the stock
        // splits evenly on both edges instead of doubling up on one.
        $first = $this->plan([], 233.0, 143.0)->fronts(1)[0][0];

        $this->assertSame(63.0 + 5.0, $first['x']);
        $this->assertSame(27.0 + 5.0, $first['y']);
    }

    public function test_an_oversized_card_overhangs_symmetrically(): void
    {
        // Not silently scaled — the print-time size warning covers this, and
        // the overhang should be visibly even rather than all on one side.
        $first = $this->plan([], 253.0, 163.0)->fronts(1)[0][0];

        $this->assertSame(63.0 - 5.0, $first['x']);
        $this->assertSame(27.0 - 5.0, $first['y']);
    }

    public function test_placement_follows_the_grid_including_gutters(): void
    {
        $pages = $this->plan(['gutter_x' => 9.0, 'gutter_y' => 6.0])->fronts(4);

        // Second cell: one card width + the gutter to the right.
        $this->assertSame(63.0 + 243.0 + 9.0, $pages[0][1]['x']);
        $this->assertSame(27.0, $pages[0][1]['y']);
        // Third cell wraps to the next row.
        $this->assertSame(63.0, $pages[0][2]['x']);
        $this->assertSame(27.0 + 153.0 + 6.0, $pages[0][2]['y']);
    }

    // ---- backs ------------------------------------------------------------

    public function test_backs_mirror_columns_for_a_long_edge_flip(): void
    {
        // Flipping the stack about its long (vertical) edge swaps left/right,
        // so back #1 must be printed in the right-hand column.
        $backs = $this->plan(['duplex_flip' => 'long_edge'])->backs(4);

        $this->assertSame([0, 1, 2, 3], array_column($backs[0], 'card'));
        $this->assertSame([1, 0, 3, 2], array_column($backs[0], 'cell'));
    }

    public function test_backs_mirror_rows_for_a_short_edge_flip(): void
    {
        // Flipping about the short (horizontal) edge swaps top/bottom: on a
        // 2x5 sheet, row 0 becomes row 4.
        $backs = $this->plan(['duplex_flip' => 'short_edge'])->backs(2);

        $this->assertSame([8, 9], array_column($backs[0], 'cell'));
    }

    public function test_backs_stay_put_when_the_stock_says_nothing_about_flipping(): void
    {
        $backs = $this->plan(['duplex_flip' => null])->backs(3);

        $this->assertSame([0, 1, 2], array_column($backs[0], 'cell'));
    }

    public function test_a_back_sheet_carries_exactly_its_front_sheets_cards(): void
    {
        // Sheet three holds three cards, so its backs sheet holds those three
        // — not a full sheet, and not the wrong three.
        $plan = $this->plan();
        $fronts = $plan->fronts(23);
        $backs = $plan->backs(23);

        $this->assertSame(count($fronts), count($backs));
        $this->assertSame(
            array_column($fronts[2], 'card'),
            array_column($backs[2], 'card'),
        );
    }

    public function test_backs_respect_the_start_cell_too(): void
    {
        // The partial sheet's used cells are used on both faces.
        $backs = $this->plan()->backs(2, 4);

        // Front cells 3 and 4 mirror (long edge) to 2 and 5.
        $this->assertSame([2, 5], array_column($backs[0], 'cell'));
    }

    public function test_backs_are_centred_like_fronts(): void
    {
        $back = $this->plan([], 233.0, 143.0)->backs(1)[0][0];

        // Mirrored into the right-hand column, still centred in its cell.
        $this->assertSame(63.0 + 243.0 + 5.0, $back['x']);
        $this->assertSame(27.0 + 5.0, $back['y']);
    }

    // ---- size check -------------------------------------------------------

    public function test_a_card_matching_the_cell_fits(): void
    {
        $this->assertTrue($this->plan()->fitsCell());
    }

    public function test_a_card_smaller_than_the_cell_fits(): void
    {
        $this->assertTrue($this->plan([], 200.0, 140.0)->fitsCell());
    }

    public function test_a_card_wider_or_taller_than_the_cell_does_not(): void
    {
        // What the print-time warning is built on.
        $this->assertFalse($this->plan([], 244.0, 153.0)->fitsCell());
        $this->assertFalse($this->plan([], 243.0, 154.0)->fitsCell());
    }

    public function test_a_hair_over_still_fits(): void
    {
        // Same tolerance as the stock editor's overflow check: a hundredth of
        // a point is unit-conversion dust from inch/mm entry, not a misfit.
        $this->assertTrue($this->plan([], 243.005, 153.0)->fitsCell());
    }
}
