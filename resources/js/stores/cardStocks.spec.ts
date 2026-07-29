import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function row(overrides: Partial<CardStockRow> & { id: string }): CardStockRow {
    return {
        name: overrides.id,
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
        duplex_flip: null,
        notes: null,
        per_sheet: 10,
        is_system: false,
        can_edit: true,
        can_delete: true,
        ...overrides,
    };
}

describe('useCardStocksStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('loads once and caches', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 's1' })] });
        const store = useCardStocksStore();

        await store.load();
        await store.load();

        expect(get).toHaveBeenCalledTimes(1);
        expect(store.library).toHaveLength(1);
    });

    it('reload refetches even when already loaded', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 's1' })] });
        const store = useCardStocksStore();

        await store.load();
        await store.reload();

        expect(get).toHaveBeenCalledTimes(2);
    });

    it('create appends the server row', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: row({ id: 's2', name: 'Wallet 10-up' }) });
        const store = useCardStocksStore();

        const created = await store.create({ name: 'Wallet 10-up' });

        expect(post).toHaveBeenCalledWith(
            '/api/card-stocks',
            { name: 'Wallet 10-up' },
            expect.anything(),
        );
        expect(created.id).toBe('s2');
        expect(store.library.map((s) => s.id)).toEqual(['s2']);
    });

    it('update replaces the row in place, keeping list order', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({
            data: [row({ id: 's1' }), row({ id: 's2' }), row({ id: 's3' })],
        });
        const patch = axios.patch as ReturnType<typeof vi.fn>;
        patch.mockResolvedValue({ data: row({ id: 's2', name: 'Renamed' }) });
        const store = useCardStocksStore();
        await store.load();

        await store.update('s2', { name: 'Renamed' });

        expect(store.library.map((s) => s.id)).toEqual(['s1', 's2', 's3']);
        expect(store.library[1].name).toBe('Renamed');
    });

    it('destroy drops the row', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 's1' }), row({ id: 's2' })] });
        const store = useCardStocksStore();
        await store.load();

        await store.destroy('s1');

        expect(axios.delete).toHaveBeenCalledWith(
            '/api/card-stocks/s1',
            expect.anything(),
        );
        expect(store.library.map((s) => s.id)).toEqual(['s2']);
    });

    it('exposes only the stocks an admin may edit for the editor list', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({
            data: [
                row({ id: 'sys', is_system: true, can_edit: false }),
                row({ id: 'mine' }),
            ],
        });
        const store = useCardStocksStore();
        await store.load();

        // System stocks stay listed (they're pickable when printing) but are
        // separated out so the UI can render them read-only.
        expect(store.library).toHaveLength(2);
        expect(store.editable.map((s) => s.id)).toEqual(['mine']);
    });
});
