import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTrainingsStore } from '@/stores/trainings';

vi.mock('axios');

describe('trainings store — server-paged list', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetchPage GETs the paged endpoint with sort + search and returns {data, meta}', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                data: [{ id: 't1', name: 'Forklift' }],
                meta: {
                    current_page: 1,
                    last_page: 2,
                    per_page: 25,
                    total: 30,
                },
            },
        });
        const store = useTrainingsStore();

        const res = await store.fetchPage({
            page: 1,
            per_page: 25,
            sort: 'name',
            dir: 'asc',
            q: 'fork',
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/api/trainings/list',
            expect.objectContaining({
                params: {
                    page: 1,
                    per_page: 25,
                    dir: 'asc',
                    sort: 'name',
                    q: 'fork',
                },
            }),
        );
        expect(res.meta.total).toBe(30);
        expect(res.data[0].name).toBe('Forklift');
        // The paged fetch must not disturb the picker library cache.
        expect(store.library).toEqual([]);
    });

    it('destroy removes the row and bumps the revision', async () => {
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {},
        });
        const store = useTrainingsStore();
        store.library = [{ id: 't1' } as never, { id: 't2' } as never];
        const rev = store.revision;

        await store.destroy('t1');

        expect(store.library.map((t) => t.id)).toEqual(['t2']);
        expect(store.revision).toBe(rev + 1);
    });
});
