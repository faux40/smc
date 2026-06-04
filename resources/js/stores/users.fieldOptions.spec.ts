import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore } from '@/stores/users';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (
            _url: string,
            _data: unknown,
            opts: { onSuccess?: () => void },
        ) => opts.onSuccess?.(),
        patch: (
            _url: string,
            _data: unknown,
            opts: { onSuccess?: () => void },
        ) => opts.onSuccess?.(),
    },
}));

const options = {
    department: ['Admin', 'Operations'],
    location: ['Yard 1', 'Yard 3'],
    job_title: ['Foreman'],
};

describe('users store — field options cache', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('fetches once and caches the org field options', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        expect(store.fieldOptions.department).toEqual(['Admin', 'Operations']);
        expect(store.fieldOptions.job_title).toEqual(['Foreman']);

        // Second call is served from cache — no second request.
        await store.loadFieldOptions();
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalledWith('/api/users/field-options');
    });

    it('refetches when forced', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        await store.loadFieldOptions(true);
        expect(axios.get).toHaveBeenCalledTimes(2);
    });

    // A new department/location/job_title typed into one form must surface in
    // the next form open without a page refresh — so any add/update invalidates
    // the cache and the next load refetches fresh distinct values.
    it('invalidates the cache when a peer adds a user (broadcast)', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        store.applyAdded({ id: 'peer-new' });
        await store.loadFieldOptions();

        expect(axios.get).toHaveBeenCalledTimes(2);
    });

    it('invalidates the cache when a peer updates a user (broadcast)', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        store.applyUpdated({ id: 'peer-upd' });
        await store.loadFieldOptions();

        expect(axios.get).toHaveBeenCalledTimes(2);
    });

    it('invalidates after a local create so the next open refetches', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        store.create({
            f_name: 'A',
            m_name: null,
            l_name: 'B',
            prefix_name: null,
            suffix_name: null,
            email: null,
            department: 'Brand-New Dept',
        });
        await store.loadFieldOptions();

        expect(axios.get).toHaveBeenCalledTimes(2);
    });

    it('invalidates after a local update so the next open refetches', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: options,
        });
        const store = useUsersStore();

        await store.loadFieldOptions();
        store.update('u1', {
            f_name: 'A',
            m_name: null,
            l_name: 'B',
            prefix_name: null,
            suffix_name: null,
            email: null,
            status: 'active',
            location: 'Brand-New Site',
        });
        await store.loadFieldOptions();

        expect(axios.get).toHaveBeenCalledTimes(2);
    });
});
