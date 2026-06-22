import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useReportsStore } from '@/stores/reports';

vi.mock('axios');

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

describe('reports store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetchCompletions GETs with all filters', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [{ id: 'c1' }], meta: META },
        });
        const store = useReportsStore();

        const res = await store.fetchCompletions({
            page: 2,
            per_page: 25,
            sort: null,
            dir: 'desc',
            q: 'forklift',
            from: '2026-01-01',
            to: '2026-03-01',
            user_q: 'lee',
            tags: ['t1'],
            tags_mode: 'or',
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/api/reports/completions',
            expect.objectContaining({
                params: {
                    page: 2,
                    per_page: 25,
                    q: 'forklift',
                    from: '2026-01-01',
                    to: '2026-03-01',
                    user_q: 'lee',
                    tags: ['t1'],
                    tags_mode: 'or',
                },
            }),
        );
        expect(res.meta.total).toBe(1);
    });

    it('omits empty optional params', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [], meta: { ...META, total: 0 } },
        });
        const store = useReportsStore();

        await store.fetchCompletions({ page: 1, per_page: 25, sort: null, dir: 'desc', q: '' });

        const params = (axios.get as ReturnType<typeof vi.fn>).mock.calls[0][1].params;
        expect(params).toEqual({ page: 1, per_page: 25 });
    });
});
