import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDashboardStore } from '@/stores/dashboard';

vi.mock('axios');

const META = { current_page: 1, last_page: 1, per_page: 50, total: 1 };

describe('dashboard store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('needsAction GETs the endpoint with paging + status + search params', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [{ id: 'ta1' }], meta: META },
        });
        const store = useDashboardStore();

        const res = await store.needsAction({
            page: 2,
            per_page: 50,
            sort: null,
            dir: 'desc',
            q: 'fork',
            status: 'overdue',
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/api/dashboard/needs-action',
            expect.objectContaining({
                params: { page: 2, per_page: 50, q: 'fork', status: 'overdue' },
            }),
        );
        expect(res.data[0].id).toBe('ta1');
    });

    it('omits empty status/search and never sends sort/dir', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [], meta: { ...META, total: 0 } },
        });
        const store = useDashboardStore();

        await store.needsAction({
            page: 1,
            per_page: 50,
            sort: 'name',
            dir: 'asc',
            q: '',
        });

        const call = (axios.get as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(call[1].params).toEqual({ page: 1, per_page: 50 });
    });
});
