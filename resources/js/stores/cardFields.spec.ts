import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CardFieldRow } from '@/lib/cardFields';
import { useCardFieldsStore } from '@/stores/cardFields';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));

function row(overrides: Partial<CardFieldRow> & { id: string }): CardFieldRow {
    return {
        key: 'trainer_id',
        placeholder: '${trainer_id}',
        label: 'Trainer ID',
        type: 'short',
        default_value: null,
        max_length: 100,
        seq: 0,
        ...overrides,
    };
}

describe('useCardFieldsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('loads a training’s fields once and caches them', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'f1' })] });
        const store = useCardFieldsStore();

        await store.load('t1');
        await store.load('t1');

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/api/trainings/t1/card-fields',
            expect.anything(),
        );
        expect(store.forTraining('t1')).toHaveLength(1);
    });

    it('caches per training rather than globally', async () => {
        // Two trainings on screen in one session must not read each other's
        // definitions.
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValueOnce({ data: [row({ id: 'f1', key: 'a' })] });
        get.mockResolvedValueOnce({ data: [row({ id: 'f2', key: 'b' })] });
        const store = useCardFieldsStore();

        await store.load('t1');
        await store.load('t2');

        expect(get).toHaveBeenCalledTimes(2);
        expect(store.forTraining('t1')[0].key).toBe('a');
        expect(store.forTraining('t2')[0].key).toBe('b');
    });

    it('reports an unfetched training as empty without inventing a request', async () => {
        const store = useCardFieldsStore();

        expect(store.forTraining('nope')).toEqual([]);
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('reload refetches even when already loaded', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'f1' })] });
        const store = useCardFieldsStore();

        await store.load('t1');
        await store.reload('t1');

        expect(get).toHaveBeenCalledTimes(2);
    });

    it('sync PUTs the whole set and replaces the cache with the response', async () => {
        // The server is authoritative about seq and placeholders, so the
        // response replaces the cache instead of being merged into it.
        const put = axios.put as ReturnType<typeof vi.fn>;
        put.mockResolvedValue({
            data: [row({ id: 'f1', key: 'trainer_id', seq: 0 })],
        });
        const store = useCardFieldsStore();

        const result = await store.sync('t1', [
            {
                id: null,
                key: 'trainer_id',
                label: 'Trainer ID',
                type: 'short',
                default_value: null,
            },
        ]);

        expect(put).toHaveBeenCalledWith(
            '/api/trainings/t1/card-fields',
            {
                fields: [
                    {
                        id: null,
                        key: 'trainer_id',
                        label: 'Trainer ID',
                        type: 'short',
                        default_value: null,
                    },
                ],
            },
            expect.anything(),
        );
        expect(result).toHaveLength(1);
        expect(store.forTraining('t1')[0].id).toBe('f1');
    });

    it('a sync that clears everything leaves the training loaded and empty', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'f1' })] });
        const put = axios.put as ReturnType<typeof vi.fn>;
        put.mockResolvedValue({ data: [] });
        const store = useCardFieldsStore();

        await store.load('t1');
        await store.sync('t1', []);

        expect(store.forTraining('t1')).toEqual([]);
        // Still cached — an empty set is an answer, not a missing fetch.
        await store.load('t1');
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('leaves the cache alone when a sync fails', async () => {
        // A 422 must not blank the editor's baseline; the user's rows stay put.
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({ data: [row({ id: 'f1' })] });
        const put = axios.put as ReturnType<typeof vi.fn>;
        put.mockRejectedValue(new Error('422'));
        const store = useCardFieldsStore();

        await store.load('t1');
        await expect(store.sync('t1', [])).rejects.toThrow();

        expect(store.forTraining('t1')).toHaveLength(1);
    });
});
