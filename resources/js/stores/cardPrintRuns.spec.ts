import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useCardPrintRunsStore } from '@/stores/cardPrintRuns';
import type { CardPrintRunRow } from '@/stores/cardPrintRuns';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

const capturedBindings: Record<string, (payload: unknown) => void> = {};
vi.mock('@/composables/useRealtime', () => ({
    useRealtime: vi.fn(() => ({
        bind: vi.fn((event: string, cb: (p: unknown) => void) => {
            capturedBindings[event] = cb;
        }),
        leave: vi.fn(),
    })),
}));

function run(
    overrides: Partial<CardPrintRunRow> & { id: string },
): CardPrintRunRow {
    return {
        class_training_id: 'ct1',
        topic_name: 'First Aid',
        status: 'queued',
        error: null,
        card_count: null,
        sheet_count: null,
        include_backs: false,
        start_cell: 1,
        created_at: '2026-07-31T10:00:00+00:00',
        ...overrides,
    };
}

function classChanged(classId: string, action = 'updated'): void {
    capturedBindings.ClassChanged?.({ class_id: classId, action });
}

describe('useCardPrintRunsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        Object.keys(capturedBindings).forEach(
            (k) => delete capturedBindings[k],
        );
    });

    it('has no runs for a class it has never fetched', () => {
        const store = useCardPrintRunsStore();

        // The class page renders before the fetch resolves; an empty list is
        // "nothing to show", not "no runs exist".
        expect(store.runsFor('c1')).toEqual([]);
    });

    it('loads a class once and caches', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'r1' })] });
        const store = useCardPrintRunsStore();

        await store.load('c1');
        await store.load('c1');

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/api/classes/c1/card-runs',
            expect.anything(),
        );
        expect(store.runsFor('c1').map((r) => r.id)).toEqual(['r1']);
    });

    it('keeps each class separate', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValueOnce({ data: [run({ id: 'r1' })] });
        get.mockResolvedValueOnce({ data: [run({ id: 'r2' })] });
        const store = useCardPrintRunsStore();

        await store.load('c1');
        await store.load('c2');

        expect(store.runsFor('c1').map((r) => r.id)).toEqual(['r1']);
        expect(store.runsFor('c2').map((r) => r.id)).toEqual(['r2']);
    });

    it('reload refetches an already-loaded class', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'r1' })] });
        const store = useCardPrintRunsStore();

        await store.load('c1');
        await store.reload('c1');

        expect(get).toHaveBeenCalledTimes(2);
    });

    it('create posts the run and puts it at the top', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'old' })] });
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: run({ id: 'new' }) });
        const store = useCardPrintRunsStore();
        await store.load('c1');

        const created = await store.create('c1', {
            class_training_id: 'ct1',
            card_template_id: null,
            card_stock_id: 's1',
            start_cell: 4,
            include_backs: true,
        });

        expect(post).toHaveBeenCalledWith(
            '/api/classes/c1/card-runs',
            {
                class_training_id: 'ct1',
                card_template_id: null,
                card_stock_id: 's1',
                start_cell: 4,
                include_backs: true,
            },
            expect.anything(),
        );
        expect(created.id).toBe('new');
        // Newest first, matching the order the endpoint lists them in.
        expect(store.runsFor('c1').map((r) => r.id)).toEqual(['new', 'old']);
    });

    it('create seeds the list for a class not loaded yet', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockResolvedValue({ data: run({ id: 'new' }) });
        const store = useCardPrintRunsStore();

        await store.create('c1', {
            class_training_id: 'ct1',
            card_stock_id: 's1',
            start_cell: 1,
            include_backs: false,
        });

        expect(store.runsFor('c1').map((r) => r.id)).toEqual(['new']);
    });

    it('lets a rejected create reach the caller', async () => {
        const post = axios.post as ReturnType<typeof vi.fn>;
        post.mockRejectedValue(new Error('422'));
        const store = useCardPrintRunsStore();

        // The modal turns this into a field error; swallowing it here would
        // leave the dialog looking like the run was accepted.
        await expect(
            store.create('c1', {
                class_training_id: 'ct1',
                card_stock_id: 's1',
                start_cell: 1,
                include_backs: false,
            }),
        ).rejects.toThrow('422');
    });

    it('refetches a cached class when the job reports in', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'r1' })] });
        const store = useCardPrintRunsStore();
        await store.load('c1');
        store.subscribe('org1');

        // The worker's ClassChanged is how a queued run becomes done.
        get.mockResolvedValue({
            data: [run({ id: 'r1', status: 'done', sheet_count: 2 })],
        });
        classChanged('c1');
        await Promise.resolve();

        expect(get).toHaveBeenCalledTimes(2);
        expect(store.runsFor('c1')[0].status).toBe('done');
    });

    it('ignores a class it is not showing', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'r1' })] });
        const store = useCardPrintRunsStore();
        await store.load('c1');
        store.subscribe('org1');

        classChanged('c2');
        await Promise.resolve();

        expect(get).toHaveBeenCalledTimes(1);
    });

    it('drops a deleted class rather than refetching it', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [run({ id: 'r1' })] });
        const store = useCardPrintRunsStore();
        await store.load('c1');
        store.subscribe('org1');

        classChanged('c1', 'deleted');
        await Promise.resolve();

        expect(get).toHaveBeenCalledTimes(1);
        expect(store.runsFor('c1')).toEqual([]);
    });

    it('subscribes to an org once', () => {
        const store = useCardPrintRunsStore();

        store.subscribe('org1');
        store.subscribe('org1');

        expect(store.subscribedOrgId).toBe('org1');
    });
});
