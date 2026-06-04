import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore } from '@/stores/users';

vi.mock('axios');

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
});
