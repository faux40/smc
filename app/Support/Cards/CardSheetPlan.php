<?php

namespace App\Support\Cards;

use App\Models\CardStock;

/**
 * Where every card lands on every sheet (custom-certs C4b) — sheet, cell and
 * point, for the fronts and for the backs.
 *
 * Every placement decision lives here as arithmetic over {@see
 * CardSheetGeometry}, so the FPDI step downstream only has to stamp what it's
 * told. That's deliberate: placement is where the bugs that waste purchased
 * stock would otherwise hide, and this is testable without a PDF in sight.
 *
 * Points throughout, origin at the page's top-left.
 */
class CardSheetPlan
{
    /** Slack matching CardSheetGeometry::fits() — inch/mm conversion dust. */
    private const TOLERANCE = 0.01;

    private readonly CardSheetGeometry $geometry;

    public function __construct(
        private readonly CardStock $stock,
        private readonly float $cardWidth,
        private readonly float $cardHeight,
    ) {
        $this->geometry = new CardSheetGeometry($stock);
    }

    /** Cards on a full sheet. */
    public function perSheet(): int
    {
        return $this->geometry->perSheet();
    }

    /**
     * Sheets needed, counting the cells skipped on the first one — a partial
     * sheet's used cells still occupy the grid.
     */
    public function sheetCount(int $cardCount, int $startCell = 1): int
    {
        if ($cardCount < 1) {
            return 0;
        }

        $this->assertStartCell($startCell);

        return (int) ceil(($cardCount + $startCell - 1) / $this->perSheet());
    }

    /**
     * Front placements, one list per sheet.
     *
     * @return list<list<array{card:int,cell:int,x:float,y:float}>>
     */
    public function fronts(int $cardCount, int $startCell = 1): array
    {
        return $this->pages($cardCount, $startCell, mirror: false);
    }

    /**
     * Back placements, one list per sheet, carrying the same cards as the
     * matching front sheet but mirrored for the flip the stock declares.
     *
     * Fronts and backs print as two separate PDFs: the operator prints the
     * fronts, reloads the stack and prints the backs, so the mirroring axis
     * is what decides whether back #1 lands on card #1 or card #2.
     *
     * @return list<list<array{card:int,cell:int,x:float,y:float}>>
     */
    public function backs(int $cardCount, int $startCell = 1): array
    {
        return $this->pages($cardCount, $startCell, mirror: true);
    }

    /**
     * Does the design fit the cell it's being placed in? False means the card
     * will overhang into the gutter — the print-time warning, never a scale.
     */
    public function fitsCell(): bool
    {
        return $this->cardWidth <= $this->stock->card_width + self::TOLERANCE
            && $this->cardHeight <= $this->stock->card_height + self::TOLERANCE;
    }

    /**
     * @return list<list<array{card:int,cell:int,x:float,y:float}>>
     */
    private function pages(int $cardCount, int $startCell, bool $mirror): array
    {
        $this->assertStartCell($startCell);

        if ($cardCount < 1) {
            return [];
        }

        $perSheet = $this->perSheet();
        $pages = [];
        // Cells consumed before the first card: the partial sheet's history.
        $cursor = $startCell - 1;

        for ($card = 0; $card < $cardCount; $card++, $cursor++) {
            $sheet = intdiv($cursor, $perSheet);
            $cell = $cursor % $perSheet;
            $placed = $mirror ? $this->mirrored($cell) : $cell;

            $pages[$sheet][] = [
                'card' => $card,
                'cell' => $placed,
                ...$this->pointFor($placed),
            ];
        }

        return array_values($pages);
    }

    /**
     * The cell a back lands in once the stack is flipped: about the long
     * (vertical) edge swaps columns, about the short (horizontal) edge swaps
     * rows. An unconfigured stock isn't mirrored at all — single-sided stock,
     * or a flip the org hasn't told us about, in which case leaving the layout
     * alone is the predictable answer.
     */
    private function mirrored(int $cell): int
    {
        $columns = $this->stock->column_count;
        $rows = $this->stock->row_count;

        $column = $cell % $columns;
        $row = intdiv($cell, $columns);

        return match ($this->stock->duplex_flip) {
            'long_edge' => $row * $columns + ($columns - 1 - $column),
            'short_edge' => ($rows - 1 - $row) * $columns + $column,
            default => $cell,
        };
    }

    /**
     * Top-left point for a card in a cell, centred: a design that misses the
     * cell size splits the difference across both edges rather than piling it
     * onto one.
     *
     * @return array{x:float,y:float}
     */
    private function pointFor(int $cell): array
    {
        $rect = $this->geometry->cellRect($cell);

        return [
            'x' => $rect['x'] + ($rect['width'] - $this->cardWidth) / 2,
            'y' => $rect['y'] + ($rect['height'] - $this->cardHeight) / 2,
        ];
    }

    private function assertStartCell(int $startCell): void
    {
        if ($startCell < 1 || $startCell > $this->perSheet()) {
            throw new \InvalidArgumentException(
                "Start cell {$startCell} is outside a sheet of {$this->perSheet()} cards.",
            );
        }
    }
}
