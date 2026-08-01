import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CardStocksList from '@/pages/cards/Partials/CardStocksList.vue';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';

vi.mock('axios');
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

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

async function mountWith(stocks: CardStockRow[], canDefine = true) {
    setActivePinia(createPinia());
    const store = useCardStocksStore();
    store.library = stocks;

    const wrapper = mount(CardStocksList, { props: { canDefine } });
    await flushPromises();

    return { wrapper, store };
}

describe('CardStocksList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
    });

    it('summarises each stock in the entry unit, not points', async () => {
        const { wrapper } = await mountWith([wallet()]);

        const text = wrapper.text();
        // A stock is unreadable in points; inches are what the packaging says.
        expect(text).toContain('Wallet 10-up');
        expect(text).toContain('3.375');
        expect(text).toContain('2.125');
        expect(text).toContain('2 × 5');
        expect(text).toContain('10 per sheet');
    });

    it('marks system stocks and offers no edit or delete for them', async () => {
        const { wrapper } = await mountWith([
            wallet({
                id: 'sys',
                name: 'Avery 5371',
                is_system: true,
                can_edit: false,
                can_delete: false,
            }),
        ]);

        expect(wrapper.text()).toContain('System');
        expect(wrapper.find('[data-testid="edit-sys"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="delete-sys"]').exists()).toBe(false);
    });

    it('links every stock to its calibration sheet, system ones included', async () => {
        /*
         * C6a. The sheet is how a Manager measures their printer's drift,
         * and built-in stocks are exactly what most orgs print on — so the
         * link doesn't hide behind can_edit. (Storing the measured offsets
         * still needs an org-owned stock; the measuring must not.)
         */
        const { wrapper } = await mountWith([
            wallet(),
            wallet({ id: 'sys', is_system: true, can_edit: false }),
        ]);

        expect(wrapper.get('[data-testid="calibrate-s1"]').attributes('href')).toBe(
            '/api/card-stocks/s1/calibration-sheet',
        );
        expect(wrapper.find('[data-testid="calibrate-sys"]').exists()).toBe(
            true,
        );
    });

    it('asks to edit a stock the actor may edit', async () => {
        const { wrapper } = await mountWith([wallet()]);

        await wrapper.get('[data-testid="edit-s1"]').trigger('click');

        expect(wrapper.emitted('edit')?.[0]?.[0]).toMatchObject({ id: 's1' });
    });

    it('deletes only after a confirm', async () => {
        const { wrapper, store } = await mountWith([wallet()]);
        const destroy = vi.spyOn(store, 'destroy').mockResolvedValue();
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(false));

        await wrapper.get('[data-testid="delete-s1"]').trigger('click');
        expect(destroy).not.toHaveBeenCalled();

        vi.stubGlobal('confirm', vi.fn().mockReturnValue(true));
        await wrapper.get('[data-testid="delete-s1"]').trigger('click');
        await flushPromises();

        expect(destroy).toHaveBeenCalledWith('s1');
    });

    it('hides the new-stock button from actors who cannot define', async () => {
        const { wrapper } = await mountWith([wallet()], false);

        expect(wrapper.find('[data-testid="new-stock"]').exists()).toBe(false);
    });
});
