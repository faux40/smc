import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CardSheetPreview from '@/components/CardSheetPreview.vue';
import type { CardGrid } from '@/lib/cardGeometry';

/** The 10-up wallet sheet the geometry tests use. */
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

function cells(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('[data-testid="preview-cell"]');
}

describe('CardSheetPreview', () => {
    it('draws one cell per card on the sheet', () => {
        const wrapper = mount(CardSheetPreview, { props: { grid: wallet() } });

        expect(cells(wrapper)).toHaveLength(10);
    });

    it('places cells where the geometry says', () => {
        const wrapper = mount(CardSheetPreview, { props: { grid: wallet() } });
        const first = cells(wrapper)[0];

        expect(first.attributes('x')).toBe('63');
        expect(first.attributes('y')).toBe('27');
        expect(first.attributes('width')).toBe('243');
    });

    it('draws nothing for a grid with no cards', () => {
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet({ column_count: 0 }) },
        });

        expect(cells(wrapper)).toHaveLength(0);
    });

    it('refuses to draw an absurd grid', () => {
        // Thousands of rects would hang the editor while someone is still
        // typing; the server's own limit is far higher than any real stock.
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet({ column_count: 30, row_count: 30 }) },
        });

        expect(cells(wrapper)).toHaveLength(0);
    });

    it('is inert unless asked to be selectable', () => {
        const wrapper = mount(CardSheetPreview, { props: { grid: wallet() } });

        expect(cells(wrapper)[0].attributes('role')).toBeUndefined();

        void cells(wrapper)[0].trigger('click');

        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('emits the clicked cell, numbered from one', () => {
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet(), selectable: true },
        });

        void cells(wrapper)[3].trigger('click');

        // Cells are 1-based for the user — matching the API's start_cell.
        expect(wrapper.emitted('select')).toEqual([[4]]);
    });

    it('is reachable by keyboard', () => {
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet(), selectable: true },
        });
        const cell = cells(wrapper)[2];

        expect(cell.attributes('role')).toBe('button');
        expect(cell.attributes('tabindex')).toBe('0');

        void cell.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('select')).toEqual([[3]]);
    });

    it('shows which cells the start cell skips', () => {
        // Starting at 4 means someone already peeled cards 1-3 off this sheet.
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet(), selected: 4 },
        });
        const states = cells(wrapper).map((c) => c.attributes('data-state'));

        expect(states.slice(0, 3)).toEqual(['used', 'used', 'used']);
        expect(states[3]).toBe('start');
        expect(states[4]).toBe('free');
    });

    it('marks every cell free when nothing is selected', () => {
        const wrapper = mount(CardSheetPreview, { props: { grid: wallet() } });

        expect(
            cells(wrapper).every((c) => c.attributes('data-state') === 'free'),
        ).toBe(true);
    });

    it('labels each cell for a screen reader', () => {
        const wrapper = mount(CardSheetPreview, {
            props: { grid: wallet(), selectable: true },
        });

        expect(cells(wrapper)[0].attributes('aria-label')).toBe('Cell 1 of 10');
    });
});
