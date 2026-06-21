import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUsersStore } from '@/stores/users';
import type { UserRow } from '@/stores/users';

vi.mock('axios');

function row(overrides: Partial<UserRow>): UserRow {
    return {
        id: 'u1',
        name: 'Ada',
        sort_name: 'Lovelace, Ada',
        f_name: 'Ada',
        m_name: null,
        l_name: 'Lovelace',
        prefix_name: null,
        suffix_name: null,
        email: null,
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
        can_edit: true,
        can_disable: true,
        can_delete: true,
        ...overrides,
    };
}

describe('users store — server-paged list + JSON row actions', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
        (axios.delete as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
    });

    it('fetchPage GETs the paged endpoint with every filter param', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [row({ id: 'a' })], meta: { current_page: 2, last_page: 3, per_page: 25, total: 51 } },
        });
        const store = useUsersStore();

        const res = await store.fetchPage({
            page: 2,
            per_page: 25,
            sort: 'email',
            dir: 'desc',
            q: 'dana',
            role: 'Manager',
            include_disabled: true,
            tags: ['t1', 't2'],
            tags_mode: 'or',
        });

        expect(axios.get).toHaveBeenCalledWith(
            '/api/users/list',
            expect.objectContaining({
                params: {
                    page: 2,
                    per_page: 25,
                    dir: 'desc',
                    sort: 'email',
                    q: 'dana',
                    role: 'Manager',
                    include_disabled: 1,
                    tags: ['t1', 't2'],
                    tags_mode: 'or',
                },
            }),
        );
        expect(res.meta.total).toBe(51);
        expect(res.data).toHaveLength(1);
    });

    it('fetchPage omits empty optional params', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } },
        });
        const store = useUsersStore();

        await store.fetchPage({ page: 1, per_page: 25, sort: 'name', dir: 'asc', q: '' });

        const params = (axios.get as ReturnType<typeof vi.fn>).mock.calls[0][1].params;
        expect(params).toEqual({ page: 1, per_page: 25, dir: 'asc', sort: 'name' });
        expect(params).not.toHaveProperty('q');
        expect(params).not.toHaveProperty('role');
        expect(params).not.toHaveProperty('tags');
    });

    it('disable POSTs JSON, patches the cached row, and bumps the revision', async () => {
        const store = useUsersStore();
        store.hydrate([row({ id: 'u1', status: 'active' })]);
        const rev = store.revision;

        await store.disable('u1');

        expect(axios.post).toHaveBeenCalledWith(
            '/users/u1/disable',
            {},
            expect.anything(),
        );
        expect(store.byId('u1')?.status).toBe('disabled');
        expect(store.revision).toBe(rev + 1);
    });

    it('enable POSTs JSON, patches the cached row, and bumps the revision', async () => {
        const store = useUsersStore();
        store.hydrate([row({ id: 'u1', status: 'disabled' })]);
        const rev = store.revision;

        await store.enable('u1');

        expect(axios.post).toHaveBeenCalledWith(
            '/users/u1/enable',
            {},
            expect.anything(),
        );
        expect(store.byId('u1')?.status).toBe('active');
        expect(store.revision).toBe(rev + 1);
    });

    it('destroy DELETEs JSON, drops the cached row, and bumps the revision', async () => {
        const store = useUsersStore();
        store.hydrate([row({ id: 'u1' }), row({ id: 'u2' })]);
        const rev = store.revision;

        await store.destroy('u1');

        expect(axios.delete).toHaveBeenCalledWith('/users/u1', expect.anything());
        expect(store.byId('u1')).toBeUndefined();
        expect(store.byId('u2')).toBeDefined();
        expect(store.revision).toBe(rev + 1);
    });
});
