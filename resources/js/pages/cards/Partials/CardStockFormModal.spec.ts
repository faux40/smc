import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CardStockFormModal from '@/pages/cards/Partials/CardStockFormModal.vue';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';

vi.mock('axios');

/** The 10-up wallet sheet, in points as the API stores it. */
function wallet(overrides: Partial<CardStockRow> = {}): CardStockRow {
    return {
        id: 's1',
        name: 'Wallet 10-up',
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
        duplex_flip: null,
        notes: null,
        per_sheet: 10,
        is_system: false,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

async function openWith(editing: CardStockRow | null = null) {
    const wrapper = mount(CardStockFormModal, {
        props: { open: false, editing },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const field = (id: string): HTMLInputElement =>
    document.body.querySelector<HTMLInputElement>(`#${id}`)!;

async function setField(id: string, value: string): Promise<void> {
    const el = field(id);
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

function clickTestId(testid: string): void {
    document.body
        .querySelector<HTMLElement>(`[data-testid="${testid}"]`)!
        .click();
}

function submitForm(): void {
    document.body
        .querySelector('form')!
        .dispatchEvent(
            new Event('submit', { cancelable: true, bubbles: true }),
        );
}

describe('CardStockFormModal', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('sends measurements as points, whatever unit was typed', async () => {
        const store = useCardStocksStore();
        const create = vi.spyOn(store, 'create').mockResolvedValue(wallet());

        await openWith();
        await setField('cs_name', 'Wallet 10-up');
        await setField('cs_page_width', '8.5');
        await setField('cs_page_height', '11');
        await setField('cs_card_width', '3.375');
        await setField('cs_card_height', '2.125');
        await setField('cs_margin_left', '0.875');
        await setField('cs_margin_top', '0.375');
        await setField('cs_column_count', '2');
        await setField('cs_row_count', '5');

        submitForm();
        await flushPromises();

        // Inches in the form, points on the wire — the API is unit-free.
        expect(create).toHaveBeenCalledWith(
            expect.objectContaining({
                name: 'Wallet 10-up',
                page_width: 612,
                page_height: 792,
                card_width: 243,
                card_height: 153,
                margin_left: 63,
                margin_top: 27,
                column_count: 2,
                row_count: 5,
            }),
        );
    });

    it('restates the same physical size when the unit changes', async () => {
        await openWith();
        await setField('cs_page_width', '8.5');

        clickTestId('unit-mm');
        await flushPromises();

        // 8.5in is 215.9mm — the sheet did not change size, only the ruler.
        expect(field('cs_page_width').value).toBe('215.9');
    });

    it('seeds from the stock being edited, in the display unit', async () => {
        const store = useCardStocksStore();
        const update = vi.spyOn(store, 'update').mockResolvedValue(wallet());

        await openWith(wallet());

        expect(field('cs_name').value).toBe('Wallet 10-up');
        expect(field('cs_page_width').value).toBe('8.5');
        expect(field('cs_card_width').value).toBe('3.375');

        await setField('cs_name', 'Renamed');
        submitForm();
        await flushPromises();

        expect(update).toHaveBeenCalledWith(
            's1',
            expect.objectContaining({ name: 'Renamed', page_width: 612 }),
        );
    });

    it('sends the calibration nudge in points, negatives included', async () => {
        /*
         * C6a: the whole-sheet printer correction. Same unit discipline as
         * every other length — typed in the display unit, points on the
         * wire — and negative is half the point: a printer can drift up and
         * left as easily as down and right.
         */
        const store = useCardStocksStore();
        const update = vi.spyOn(store, 'update').mockResolvedValue(wallet());

        await openWith(wallet());
        await setField('cs_offset_x', '0.125');
        await setField('cs_offset_y', '-0.125');

        submitForm();
        await flushPromises();

        expect(update).toHaveBeenCalledWith(
            's1',
            expect.objectContaining({ offset_x: 9, offset_y: -9 }),
        );
    });

    it('refuses a nudge that pushes the grid off the page', async () => {
        // The same rule as any other overflow: a clipped card is wasted
        // stock, so the client mirror blocks the save the API would reject.
        const store = useCardStocksStore();
        const update = vi.spyOn(store, 'update').mockResolvedValue(wallet());

        await openWith(wallet());
        // The 10-up grid ends flush with the page bottom; any downward
        // nudge overflows.
        await setField('cs_offset_y', '0.05');

        submitForm();
        await flushPromises();

        expect(update).not.toHaveBeenCalled();
        expect(
            document.body.querySelector('[data-testid="overflow-warning"]'),
        ).not.toBeNull();
    });

    it('shows the cards-per-sheet count as the grid changes', async () => {
        await openWith(wallet());

        expect(
            document.body.querySelector('[data-testid="per-sheet"]')!
                .textContent,
        ).toContain('10');

        await setField('cs_row_count', '4');

        expect(
            document.body.querySelector('[data-testid="per-sheet"]')!
                .textContent,
        ).toContain('8');
    });

    it('warns and refuses to save a grid that runs off the page', async () => {
        const store = useCardStocksStore();
        const update = vi.spyOn(store, 'update').mockResolvedValue(wallet());

        await openWith(wallet());
        await setField('cs_column_count', '3'); // 3 x 3.375in > 8.5in page

        expect(
            document.body.querySelector('[data-testid="overflow-warning"]'),
        ).not.toBeNull();

        submitForm();
        await flushPromises();

        // The server would reject it too; catching it here saves the trip
        // and points at the field that caused it.
        expect(update).not.toHaveBeenCalled();
    });

    it('renders a cell per card in the sheet preview', async () => {
        await openWith(wallet());

        expect(
            document.body.querySelectorAll('[data-testid="preview-cell"]'),
        ).toHaveLength(10);
    });
});
