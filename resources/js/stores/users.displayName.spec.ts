import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore, type UserRow } from '@/stores/users';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: vi.fn() },
}));

function row(overrides: Partial<UserRow> = {}): UserRow {
    return {
        id: 'u1',
        name: 'Ada Augusta Lovelace',
        sort_name: 'Lovelace, Ada Augusta',
        f_name: 'Ada',
        m_name: 'Augusta',
        l_name: 'Lovelace',
        prefix_name: null,
        suffix_name: null,
        email: 'ada@example.com',
        status: 'active',
        role: null,
        department: null,
        location: null,
        job_title: null,
        employee_number: null,
        supervisor_id: null,
        supervisor_name: null,
        supervisor_sort_name: null,
        start_date: null,
        end_date: null,
        notes: null,
        created_at: null,
        tag_ids: [],
        can_edit: false,
        can_disable: false,
        can_delete: false,
        ...overrides,
    };
}

describe('users store — name resolution via the store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('displayName returns the sortable name for a hydrated user', () => {
        const store = useUsersStore();
        store.hydrate([row()]);

        expect(store.displayName('u1')).toBe('Lovelace, Ada Augusta');
    });

    it('byId looks a row up by id (and is undefined when absent)', () => {
        const store = useUsersStore();
        store.hydrate([row()]);

        expect(store.byId('u1')?.email).toBe('ada@example.com');
        expect(store.byId('nope')).toBeUndefined();
    });

    it('displayName falls back to name, then email, then empty', () => {
        const store = useUsersStore();
        store.hydrate([
            row({ id: 'a', sort_name: '', name: 'Just Name' }),
            row({ id: 'b', sort_name: '', name: '', email: 'only@email.com' }),
            row({ id: 'c', sort_name: '', name: '', email: null }),
        ]);

        expect(store.displayName('a')).toBe('Just Name');
        expect(store.displayName('b')).toBe('only@email.com');
        expect(store.displayName('c')).toBe('');
    });

    it('displayName is empty for an unknown id', () => {
        const store = useUsersStore();
        expect(store.displayName('ghost')).toBe('');
    });

    it('loadPicker caches sortable names from one request', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: [
                {
                    id: 'p1',
                    name: 'Bob Q Baker',
                    sort_name: 'Baker, Bob Q',
                    f_name: 'Bob',
                    m_name: 'Q',
                    l_name: 'Baker',
                    email: 'bob@example.com',
                    supervisor_id: 's1',
                    supervisor_name: 'Sam Boss',
                    supervisor_sort_name: 'Boss, Sam',
                    tag_ids: [],
                },
            ],
        });
        const store = useUsersStore();

        await store.loadPicker();
        expect(store.displayName('p1')).toBe('Baker, Bob Q');
        expect(store.byId('p1')?.supervisor_sort_name).toBe('Boss, Sam');

        // Cached — a second call makes no second request.
        await store.loadPicker();
        expect(axios.get).toHaveBeenCalledTimes(1);
    });

    it('applyAdded carries the sortable name from the broadcast', () => {
        const store = useUsersStore();
        store.applyAdded({
            id: 'new',
            name: 'Cara Cole',
            sort_name: 'Cole, Cara',
        });

        expect(store.displayName('new')).toBe('Cole, Cara');
    });

    it('applyUpdated patches the sortable name from the broadcast', () => {
        const store = useUsersStore();
        store.hydrate([row({ id: 'u1' })]);
        store.applyUpdated({
            id: 'u1',
            name: 'Ada Byron',
            sort_name: 'Byron, Ada',
        });

        expect(store.displayName('u1')).toBe('Byron, Ada');
    });
});
