import { describe, expect, it } from 'vitest';
import {
    cellRects,
    fitsCell,
    fromPoints,
    perSheet,
    sheetCount,
    sheetFits,
    toPoints,
} from '@/lib/cardGeometry';
import type { CardGrid } from '@/lib/cardGeometry';

/** The 10-up wallet sheet the PHP CardSheetGeometry tests use. */
function wallet(overrides: Partial<CardGrid> = {}): CardGrid {
    return {
        page_width: 612,
        page_height: 792,
        column_count: 2,
        row_count: 5,
        card_width: 243,
        card_height: 153,
        margin_top: 27,
        margin_left: 63,
        gutter_x: 0,
        gutter_y: 0,
        offset_x: 0,
        offset_y: 0,
        ...overrides,
    };
}

describe('cardGeometry', () => {
    it('counts the cards on a full sheet', () => {
        expect(perSheet(wallet())).toBe(10);
    });

    it('lays cells out left to right then down, from the margins', () => {
        const cells = cellRects(wallet());

        expect(cells).toHaveLength(10);
        expect(cells[0]).toEqual({ x: 63, y: 27, width: 243, height: 153 });
        // Second column of row 0, then the wrap to row 1.
        expect(cells[1].x).toBe(63 + 243);
        expect(cells[1].y).toBe(27);
        expect(cells[2].x).toBe(63);
        expect(cells[2].y).toBe(27 + 153);
    });

    it('pushes later cells out by the gutters', () => {
        const cells = cellRects(wallet({ gutter_x: 18, gutter_y: 9 }));

        expect(cells[1].x).toBe(63 + 243 + 18);
        expect(cells[2].y).toBe(27 + 153 + 9);
    });

    it('reports whether the grid stays on the page', () => {
        // Mirrors the server rule — this preview is advisory, the API is
        // authoritative, so the two must agree.
        expect(sheetFits(wallet())).toBe(true);
        expect(sheetFits(wallet({ column_count: 3 }))).toBe(false);
        expect(sheetFits(wallet({ row_count: 6 }))).toBe(false);
        expect(sheetFits(wallet({ gutter_y: 72 }))).toBe(false);
    });

    it('nudges every cell by the calibration offsets', () => {
        // Cases track CardSheetGeometryTest's offset block (C6a).
        const cells = cellRects(wallet({ offset_x: 4.5, offset_y: -3 }));

        expect(cells[0].x).toBe(63 + 4.5);
        expect(cells[0].y).toBe(27 - 3);
        // A shift, not a scale: the last cell moves by the same amount and
        // the cell size is untouched.
        expect(cells[9].x).toBe(63 + 243 + 4.5);
        expect(cells[9].y).toBe(27 + 4 * 153 - 3);
        expect(cells[0].width).toBe(243);
        expect(cells[0].height).toBe(153);
    });

    it('counts the offsets toward the fit, in both directions', () => {
        // ±1mm within the margins is the normal case…
        expect(sheetFits(wallet({ offset_x: 2.83, offset_y: -2.83 }))).toBe(
            true,
        );
        // …off the right edge (63pt of room on this stock)…
        expect(sheetFits(wallet({ offset_x: 64 }))).toBe(false);
        // …any downward nudge on a grid that ends at the page edge…
        expect(sheetFits(wallet({ offset_y: 2.83 }))).toBe(false);
        // …and up past the top margin clips row 0.
        expect(sheetFits(wallet({ offset_y: -36 }))).toBe(false);
    });
});

/*
 * sheetCount + fitsCell mirror CardSheetPlan (the PHP), case for case, so the
 * print modal's "N cards → M sheets" and its size warning say what the job
 * will actually do. Cases below track CardSheetPlanTest.
 */
describe('sheetCount', () => {
    it('fits a full sheet on one sheet', () => {
        expect(sheetCount(wallet(), 10)).toBe(1);
    });

    it('starts another sheet for one card past the first', () => {
        expect(sheetCount(wallet(), 11)).toBe(2);
    });

    it('spreads twenty-three cards over three sheets', () => {
        expect(sheetCount(wallet(), 23)).toBe(3);
    });

    it('needs no sheets for no cards', () => {
        expect(sheetCount(wallet(), 0)).toBe(0);
    });

    it('counts the skipped start cells toward the sheet total', () => {
        // Eight cards onto a sheet with three cells already used: 7 fit, so
        // the eighth opens a second sheet.
        expect(sheetCount(wallet(), 8, 4)).toBe(2);
    });

    it('skips cells only on the first sheet', () => {
        // 10-up: 7 on the partial first sheet, 10 on the second, 3 on a third.
        expect(sheetCount(wallet(), 20, 4)).toBe(3);
    });

    /*
     * The server throws on a start cell off the sheet; a computed property in
     * a modal must not. null means "not computable" — the caller shows the
     * stock's capacity and blocks the submit the API would 422 anyway. This
     * is reachable: pick cell 9, then switch to a 4-up stock.
     */
    it('cannot count when the start cell is off the sheet', () => {
        expect(sheetCount(wallet(), 1, 11)).toBeNull();
    });

    it('cannot count when the start cell is below one', () => {
        // Cells are 1-based for the user; 0 would silently mean "cell -1".
        expect(sheetCount(wallet(), 1, 0)).toBeNull();
    });

    it('still needs no sheets for no cards, whatever the start cell', () => {
        // Matches the server's early return, which precedes its start-cell check.
        expect(sheetCount(wallet(), 0, 11)).toBe(0);
    });
});

describe('fitsCell', () => {
    it('accepts a card that matches its cell exactly', () => {
        expect(fitsCell(243, 153, wallet())).toBe(true);
    });

    it('accepts a card smaller than its cell', () => {
        expect(fitsCell(200, 140, wallet())).toBe(true);
    });

    it('rejects a card wider or taller than its cell', () => {
        // What the print-time overhang warning is built on.
        expect(fitsCell(244, 153, wallet())).toBe(false);
        expect(fitsCell(243, 154, wallet())).toBe(false);
    });

    it('absorbs unit-conversion dust, like the server', () => {
        // A hundredth of a point — a card measured in mm lands here.
        expect(fitsCell(243.005, 153.005, wallet())).toBe(true);
        expect(fitsCell(243.02, 153, wallet())).toBe(false);
    });
});

describe('unit conversion', () => {
    it('converts inches and millimetres to points', () => {
        expect(toPoints(1, 'in')).toBe(72);
        expect(toPoints(8.5, 'in')).toBe(612);
        expect(toPoints(25.4, 'mm')).toBeCloseTo(72, 6);
    });

    it('converts points back for display', () => {
        expect(fromPoints(612, 'in')).toBe(8.5);
        expect(fromPoints(72, 'mm')).toBeCloseTo(25.4, 6);
    });

    it('round-trips a typed value without drift', () => {
        // A card measured at 3.375in must come back as 3.375in, not 3.3749.
        expect(fromPoints(toPoints(3.375, 'in'), 'in')).toBe(3.375);
        expect(fromPoints(toPoints(85.6, 'mm'), 'mm')).toBe(85.6);
    });
});
