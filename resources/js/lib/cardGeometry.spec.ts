import { describe, expect, it } from 'vitest';
import {
    cellRects,
    fromPoints,
    perSheet,
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
