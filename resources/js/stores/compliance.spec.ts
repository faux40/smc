import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useComplianceStore } from '@/stores/compliance';

vi.mock('axios');

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

describe('compliance store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('byTraining GETs the by-training endpoint with paging/sort/search', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                data: [{ id: 't1', name: 'Forklift', total: 3, counts: {} }],
                meta: META,
            },
        });
        const store = useComplianceStore();

        const res = await store.byTraining({
            page: 2,
            per_page: 25,
            sort: 'overdue',
            dir: 'desc',
            q: 'fork',
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/api/compliance/by-training',
            expect.objectContaining({
                params: {
                    page: 2,
                    per_page: 25,
                    dir: 'desc',
                    sort: 'overdue',
                    q: 'fork',
                },
            }),
        );
        expect(res.data[0].name).toBe('Forklift');
    });

    it('byRequirement hits the by-requirement endpoint and omits empty params', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [], meta: { ...META, total: 0 } },
        });
        const store = useComplianceStore();

        await store.byRequirement({
            page: 1,
            per_page: 25,
            sort: null,
            dir: 'asc',
            q: '',
        });

        const call = (axios.get as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(call[0]).toBe('/api/compliance/by-requirement');
        expect(call[1].params).toEqual({ page: 1, per_page: 25, dir: 'asc' });
    });
});
