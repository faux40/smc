import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore } from '@/stores/users';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
}));

const createdRow = {
    id: 'new1',
    name: 'Ada Lovelace',
    sort_name: 'Lovelace, Ada',
    f_name: 'Ada',
    m_name: null,
    l_name: 'Lovelace',
    email: 'ada@example.com',
    employee_number: null,
    department: null,
    location: null,
    job_title: null,
    supervisor_id: null,
    supervisor_name: null,
    supervisor_sort_name: null,
    tag_ids: [],
};

describe('users store — createReturning (inline JSON create)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('posts the form as JSON and returns the created row', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: createdRow,
        });
        const store = useUsersStore();

        const row = await store.createReturning({
            f_name: 'Ada',
            m_name: null,
            l_name: 'Lovelace',
            prefix_name: null,
            suffix_name: null,
            email: 'ada@example.com',
        });

        expect(row.id).toBe('new1');
        expect(axios.post).toHaveBeenCalledTimes(1);
        // The new user is in the store cache, resolvable by id.
        expect(store.displayName('new1')).toBe('Lovelace, Ada');
    });
});

describe('users store — loadPicker(force)', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('refetches when forced even if the cache is populated', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [createdRow],
        });
        const store = useUsersStore();

        await store.loadPicker();
        await store.loadPicker(); // cached → no second request
        expect(axios.get).toHaveBeenCalledTimes(1);

        await store.loadPicker(true); // forced → refetch
        expect(axios.get).toHaveBeenCalledTimes(2);
    });
});
