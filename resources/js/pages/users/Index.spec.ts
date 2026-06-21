import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UsersIndex from '@/pages/users/Index.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import type { UserRow } from '@/stores/users';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1' } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/users', () => ({
    index: () => ({ url: '/users' }),
    show: (id: string) => ({ url: `/users/${id}` }),
    store: () => ({ url: '/users' }),
    update: (id: string) => ({ url: `/users/${id}` }),
    disable: (id: string) => ({ url: `/users/${id}/disable` }),
    enable: (id: string) => ({ url: `/users/${id}/enable` }),
    destroy: (id: string) => ({ url: `/users/${id}` }),
}));

function user(overrides: Partial<UserRow>): UserRow {
    return {
        id: 'u',
        name: 'X',
        sort_name: 'X',
        f_name: 'X',
        m_name: null,
        l_name: 'X',
        prefix_name: null,
        suffix_name: null,
        email: null,
        status: 'active',
        role: 'None',
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

const users: UserRow[] = [
    user({ id: 'u2', name: 'Amy Adams', sort_name: 'Adams, Amy', f_name: 'Amy', l_name: 'Adams', department: 'Admin' }),
    user({ id: 'u3', name: 'Bob Baker', sort_name: 'Baker, Bob', f_name: 'Bob', l_name: 'Baker' }),
    user({ id: 'u1', name: 'Zoe Charlie', sort_name: 'Charlie, Zoe', f_name: 'Zoe', l_name: 'Charlie', department: 'Ops' }),
];

const META = { current_page: 1, last_page: 1, per_page: 25, total: users.length };

/** Route axios.get by URL: the paged list, plus the bulk-grid lookups. */
function stubAxios(rows: UserRow[] = users): void {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string) => {
            if (url === '/api/users/list') {
                return Promise.resolve({ data: { data: rows, meta: { ...META, total: rows.length } } });
            }
            if (url === '/api/users/field-options') {
                return Promise.resolve({ data: { department: [], location: [], job_title: [] } });
            }
            // picker roster (loadPicker) — empty is fine for these tests
            return Promise.resolve({ data: [] });
        },
    );
}

/** Params of each GET to the paged list endpoint, in call order. */
function listParams(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === '/api/users/list')
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

function mountIndex(canCreate = false) {
    return mount(UsersIndex, {
        props: {
            filters: { q: '', role: '', include_disabled: false, tags: [], tags_mode: 'and' },
            can_create: canCreate,
        },
        global: {
            stubs: { TagFilter: true, TagsListCell: true, UserFormModal: true },
        },
    });
}

describe('users/Index — server-paged table', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        sessionStorage.clear();
        authUser.value = { id: 'me', org_id: 'org1' };
        stubAxios();
    });

    it('renders the profile column headers', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        for (const h of ['Job title', 'Employee #', 'Department', 'Location', 'Supervisor']) {
            expect(headers.some((x) => x.includes(h))).toBe(true);
        }
    });

    it('fetches page 1 sorted by name ascending on mount', async () => {
        mountIndex();
        await flushPromises();

        expect(listParams()[0]).toMatchObject({
            page: 1,
            per_page: 25,
            sort: 'name',
            dir: 'asc',
        });
    });

    it('renders the rows the server returns', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        const cells = wrapper.findAll('tbody tr').map((tr) => tr.find('td').text().trim());
        expect(cells).toEqual(['Adams, Amy', 'Baker, Bob', 'Charlie, Zoe']);
    });

    it("shows each user's supervisor name in the Supervisor column", async () => {
        stubAxios([
            user({ id: 'u9', sort_name: 'Lee, Pat', supervisor_sort_name: 'Boss, Dana' }),
        ]);
        const wrapper = mountIndex();
        await flushPromises();
        expect(wrapper.find('tbody').text()).toContain('Boss, Dana');
    });

    it('hides a column the user has turned off in their preferences', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { users: { visible_columns: { email: false } } },
        };
        const wrapper = mountIndex();
        await flushPromises();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Email'))).toBe(false);
        expect(headers.some((h) => h.includes('Name'))).toBe(true);
    });

    it('re-fetches with the server sort key when a header is clicked', async () => {
        const wrapper = mountIndex();
        await flushPromises();

        const btn = wrapper.findAll('thead button').find((b) => b.text().includes('Department'));
        await btn!.trigger('click');
        await flushPromises();

        expect(listParams().at(-1)).toMatchObject({ sort: 'department', dir: 'asc' });
    });
});

describe('users/Index — live filtering', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        sessionStorage.clear();
        authUser.value = { id: 'me', org_id: 'org1' };
        stubAxios();
    });

    it('restores the session filter on a clean visit (initial fetch carries it)', async () => {
        sessionStorage.setItem('tableFilters:users', JSON.stringify({ q: 'saved-term', role: '' }));
        mountIndex();
        await flushPromises();

        expect(listParams()[0]).toMatchObject({ q: 'saved-term' });
    });

    it('shows a Clear control only when a filter is active, and clears it', async () => {
        sessionStorage.setItem('tableFilters:users', JSON.stringify({ q: 'dana' }));
        const wrapper = mountIndex();
        await flushPromises();

        const clearBtn = wrapper.findAll('button').find((b) => b.text().includes('Clear filters'));
        expect(clearBtn).toBeTruthy();

        await clearBtn!.trigger('click');
        await flushPromises();

        // Re-queries unfiltered (q dropped) and drops the session entry.
        expect(listParams().at(-1)?.q).toBeUndefined();
        expect(sessionStorage.getItem('tableFilters:users')).toBeNull();
    });

    it('has no Apply button — filters apply live', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        expect(wrapper.findAll('button').some((b) => b.text().trim() === 'Apply')).toBe(false);
    });

    it('applies the search filter as you type, debounced', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        const before = listParams().length;

        vi.useFakeTimers();
        const search = wrapper.find(
            'input[placeholder="Search name, email, title, dept, location, emp #"]',
        );
        await search.setValue('dana');

        expect(listParams().length).toBe(before); // still within debounce window

        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();

        expect(listParams().at(-1)).toMatchObject({ q: 'dana' });
    });

    it('re-pulls the current page after a bulk add completes', async () => {
        const wrapper = mountIndex(true);
        await flushPromises();

        const bulkBtn = wrapper.findAll('button').find((b) => b.text() === 'Bulk add')!;
        await bulkBtn.trigger('click');
        await flushPromises();
        const before = listParams().length;

        const grid = wrapper.findComponent(UsersBulkAddGrid);
        expect(grid.exists()).toBe(true);

        vi.useFakeTimers();
        grid.vm.$emit('done');
        await vi.advanceTimersByTimeAsync(400); // refetchSoon debounce
        vi.useRealTimers();
        await flushPromises();

        expect(listParams().length).toBeGreaterThan(before);
    });
});
