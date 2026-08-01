/*
 * Client-side mirror of the server's CardSheetGeometry (app/Support/Cards),
 * for the live preview in the stock editor: a round-trip per keystroke would
 * be worse than this duplication. The SERVER is authoritative — it validates
 * every save with the same rule — so the two implementations must agree, and
 * both are covered by matching tests.
 *
 * Points (1/72in) throughout, origin at the page's top-left, cells numbered
 * left to right then down.
 */

export interface CardGrid {
    page_width: number;
    page_height: number;
    column_count: number;
    row_count: number;
    card_width: number;
    card_height: number;
    margin_top: number;
    margin_left: number;
    gutter_x: number;
    gutter_y: number;
    /**
     * Calibration nudge (C6a): a whole-sheet shift for a printer that lands
     * the image slightly off the paper. One pair for both duplex passes.
     */
    offset_x: number;
    offset_y: number;
}

export interface CellRect {
    x: number;
    y: number;
    width: number;
    height: number;
}

export type LengthUnit = 'in' | 'mm';

/** Points per unit. */
const PER_UNIT: Record<LengthUnit, number> = {
    in: 72,
    mm: 72 / 25.4,
};

/** A hundredth of a point of slack, matching the server's fit check. */
const SLACK = 0.01;

export function perSheet(grid: CardGrid): number {
    return grid.column_count * grid.row_count;
}

export function cellRects(grid: CardGrid): CellRect[] {
    const cells: CellRect[] = [];

    for (let index = 0; index < perSheet(grid); index++) {
        const column = index % grid.column_count;
        const row = Math.floor(index / grid.column_count);

        cells.push({
            x:
                grid.margin_left +
                grid.offset_x +
                column * (grid.card_width + grid.gutter_x),
            y:
                grid.margin_top +
                grid.offset_y +
                row * (grid.card_height + grid.gutter_y),
            width: grid.card_width,
            height: grid.card_height,
        });
    }

    return cells;
}

export function usedWidth(grid: CardGrid): number {
    return (
        grid.margin_left +
        grid.column_count * grid.card_width +
        Math.max(0, grid.column_count - 1) * grid.gutter_x
    );
}

export function usedHeight(grid: CardGrid): number {
    return (
        grid.margin_top +
        grid.row_count * grid.card_height +
        Math.max(0, grid.row_count - 1) * grid.gutter_y
    );
}

export function sheetFits(grid: CardGrid): boolean {
    // Offsets count in both directions, as on the server: a positive nudge
    // can push the far edge off the page, a negative one can pull row or
    // column 0 off the near edge — either way a card clips.
    return (
        usedWidth(grid) + grid.offset_x <= grid.page_width + SLACK &&
        usedHeight(grid) + grid.offset_y <= grid.page_height + SLACK &&
        grid.margin_left + grid.offset_x >= -SLACK &&
        grid.margin_top + grid.offset_y >= -SLACK
    );
}

/**
 * Sheets a run needs, counting the cells skipped on the first one — a partial
 * sheet's used cells still occupy the grid.
 *
 * null when the start cell is off the sheet. The server throws there, but this
 * feeds a live count in the print modal, and a computed property that throws
 * takes the dialog down with it; the state is reachable by picking cell 9 and
 * then switching to a 4-up stock. The caller shows the stock's capacity and
 * blocks the submit that the API would reject anyway.
 */
export function sheetCount(
    grid: CardGrid,
    cardCount: number,
    startCell = 1,
): number | null {
    // Ahead of the start-cell check, as on the server: nothing to place means
    // nothing to place onto.
    if (cardCount < 1) {
        return 0;
    }

    const per = perSheet(grid);

    if (startCell < 1 || startCell > per) {
        return null;
    }

    return Math.ceil((cardCount + startCell - 1) / per);
}

/**
 * Does a design fit the cell it will be placed in? False means the card
 * overhangs into the gutter — the print-time warning, never a scale.
 *
 * Card dimensions are the template's, read from its slide size at upload.
 */
export function fitsCell(
    cardWidth: number,
    cardHeight: number,
    grid: CardGrid,
): boolean {
    return (
        cardWidth <= grid.card_width + SLACK &&
        cardHeight <= grid.card_height + SLACK
    );
}

/** Entered length → the points the API stores. */
export function toPoints(value: number, unit: LengthUnit): number {
    return value * PER_UNIT[unit];
}

/**
 * Points → a displayable length. Rounded to 4 decimals so a typed 3.375in
 * survives the round-trip as 3.375 rather than 3.3749999999999996.
 */
export function fromPoints(points: number, unit: LengthUnit): number {
    return Math.round((points / PER_UNIT[unit]) * 10000) / 10000;
}
