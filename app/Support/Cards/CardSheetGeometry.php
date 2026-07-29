<?php

namespace App\Support\Cards;

use App\Models\CardStock;

/**
 * Where each card lands on a sheet of purchased stock. Pure arithmetic over a
 * {@see CardStock}: points throughout, origin at the page's top-left, cells
 * numbered left-to-right then down.
 *
 * This is the one place the grid is computed — the stock editor validates
 * against it, and the imposition step places merged card pages with it.
 */
class CardSheetGeometry
{
    public function __construct(private readonly CardStock $stock) {}

    /** Cards on a full sheet. */
    public function perSheet(): int
    {
        return $this->stock->column_count * $this->stock->row_count;
    }

    /**
     * Rect of one cell, `['x', 'y', 'width', 'height']` in points.
     *
     * @throws \InvalidArgumentException when the index is off the sheet
     */
    public function cellRect(int $index): array
    {
        if ($index < 0 || $index >= $this->perSheet()) {
            throw new \InvalidArgumentException(
                "Cell {$index} is off a {$this->stock->column_count}x{$this->stock->row_count} sheet.",
            );
        }

        $column = $index % $this->stock->column_count;
        $row = intdiv($index, $this->stock->column_count);

        return [
            'x' => $this->stock->margin_left + $column * ($this->stock->card_width + $this->stock->gutter_x),
            'y' => $this->stock->margin_top + $row * ($this->stock->card_height + $this->stock->gutter_y),
            'width' => $this->stock->card_width,
            'height' => $this->stock->card_height,
        ];
    }

    /** Total width the grid occupies, margin included. */
    public function usedWidth(): float
    {
        return $this->stock->margin_left
            + $this->stock->column_count * $this->stock->card_width
            + max(0, $this->stock->column_count - 1) * $this->stock->gutter_x;
    }

    /** Total height the grid occupies, margin included. */
    public function usedHeight(): float
    {
        return $this->stock->margin_top
            + $this->stock->row_count * $this->stock->card_height
            + max(0, $this->stock->row_count - 1) * $this->stock->gutter_y;
    }

    /**
     * Does the grid stay on the page? A hundredth of a point of slack absorbs
     * unit-conversion dust from inch/mm entry — anything larger is a real
     * overflow the user has to fix.
     */
    public function fits(): bool
    {
        return $this->usedWidth() <= $this->stock->page_width + 0.01
            && $this->usedHeight() <= $this->stock->page_height + 0.01;
    }
}
