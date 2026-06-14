import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRequirementsStore } from '@/stores/requirements';

vi.mock('axios');

describe('useRequirementsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetchPage() requests the paged endpoint and returns {data, meta}', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        const meta = {
            current_page: 2,
            last_page: 3,
            per_page: 25,
            total: 60,
        };
        get.mockResolvedValue({ data: { data: [{ id: 'r1' }], meta } });
        const store = useRequirementsStore();

        const res = await store.fetchPage({
            page: 2,
            per_page: 25,
            dir: 'asc',
            sort: 'name',
            q: 'forklift',
        });

        expect(get).toHaveBeenCalledWith(
            '/api/requirements/paged',
            expect.objectContaining({
                params: expect.objectContaining({
                    page: 2,
                    per_page: 25,
                    dir: 'asc',
                    sort: 'name',
                    q: 'forklift',
                }),
            }),
        );
        expect(res.data).toHaveLength(1);
        expect(res.meta.total).toBe(60);
    });

    it('fetchPage() omits empty sort and q', async () => {
        const get = axios.get as ReturnType<typeof vi.fn>;
        get.mockResolvedValue({
            data: {
                data: [],
                meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
            },
        });
        const store = useRequirementsStore();

        await store.fetchPage({
            page: 1,
            per_page: 25,
            dir: 'asc',
            sort: null,
            q: '',
        });

        const params = get.mock.calls[0][1].params;
        expect(params).not.toHaveProperty('sort');
        expect(params).not.toHaveProperty('q');
    });

    it('load() populates the flat library from the picker endpoint', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [
                { id: 'r1', name: 'A', description: null, elements_count: 0 },
            ],
        });
        const store = useRequirementsStore();

        await store.load();

        expect(axios.get).toHaveBeenCalledWith(
            '/api/requirements',
            expect.anything(),
        );
        expect(store.library).toHaveLength(1);
    });
});
