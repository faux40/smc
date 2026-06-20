import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UsersIndex from '@/pages/users/Index.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import type { UserRow } from '@/stores/users';

const { routerGet, routerReload, authUser } = vi.hoisted(() => ({
    routerGet: vi.fn(),
    routerReload: vi.fn(),
    authUser: {
        value: { id: 'me', org_id: 'org1' } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: routerGet, reload: routerReload },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/users', () => ({
    index: () => ({ url: '/users' }),
    show: (id: string) => ({ url: `/users/${id}` }),
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
    user({
        id: 'u1',
        name: 'Zoe Charlie',
        sort_name: 'Charlie, Zoe',
        f_name: 'Zoe',
        l_name: 'Charlie',
        department: 'Ops',
    }),
    user({
        id: 'u2',
        name: 'Amy Adams',
        sort_name: 'Adams, Amy',
        f_name: 'Amy',
        l_name: 'Adams',
        department: 'Admin',
    }),
    user({
        id: 'u3',
        name: 'Bob Baker',
        sort_name: 'Baker, Bob',
        f_name: 'Bob',
        l_name: 'Baker',
        department: null,
    }),
];

function mountIndex() {
    return mount(UsersIndex, {
        props: {
            users,
            filters: {
                q: '',
                role: '',
                include_disabled: false,
                tags: [],
                tags_mode: 'and',
            },
            can_create: false,
        },
        global: {
            stubs: { TagFilter: true, TagsListCell: true, UserFormModal: true },
        },
    });
}

function nameColumn(wrapper: ReturnType<typeof mountIndex>): string[] {
    return wrapper.findAll('tbody tr').map((tr) => tr.find('td').text().trim());
}

function clickHeader(wrapper: ReturnType<typeof mountIndex>, label: string) {
    const btn = wrapper
        .findAll('thead button')
        .find((b) => b.text().includes(label));

    return btn!.trigger('click');
}

describe('users/Index — sortable columns', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('renders the new profile column headers', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Job title'))).toBe(true);
        expect(headers.some((h) => h.includes('Employee #'))).toBe(true);
        expect(headers.some((h) => h.includes('Department'))).toBe(true);
        expect(headers.some((h) => h.includes('Location'))).toBe(true);
        expect(headers.some((h) => h.includes('Supervisor'))).toBe(true);
    });

    it("shows each user's supervisor name in the Supervisor column", async () => {
        const withBoss = [
            user({
                id: 'u9',
                name: 'Pat Lee',
                sort_name: 'Lee, Pat',
                f_name: 'Pat',
                l_name: 'Lee',
                supervisor_name: 'Dana Boss',
                supervisor_sort_name: 'Boss, Dana',
            }),
        ];
        const wrapper = mount(UsersIndex, {
            props: {
                users: withBoss,
                filters: {
                    q: '',
                    role: '',
                    include_disabled: false,
                    tags: [],
                    tags_mode: 'and',
                },
                can_create: false,
            },
            global: {
                stubs: {
                    TagFilter: true,
                    TagsListCell: true,
                    UserFormModal: true,
                },
            },
        });
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

    it('defaults to last-name ascending order', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        // Adams, Baker, Charlie by last name.
        expect(nameColumn(wrapper)).toEqual([
            'Adams, Amy',
            'Baker, Bob',
            'Charlie, Zoe',
        ]);
    });

    it('reorders by department (empties last) when that header is clicked', async () => {
        const wrapper = mountIndex();
        await flushPromises();

        await clickHeader(wrapper, 'Department');
        // Admin (u2), Ops (u1), then null (u3) last.
        expect(nameColumn(wrapper)).toEqual([
            'Adams, Amy',
            'Charlie, Zoe',
            'Baker, Bob',
        ]);
    });
});

describe('users/Index — live filtering', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        sessionStorage.clear();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('restores the session filter on a clean (unfiltered) visit', async () => {
        // Session-scoped (sessionStorage), NOT the user profile.
        sessionStorage.setItem(
            'tableFilters:users',
            JSON.stringify({ q: 'saved-term', role: '' }),
        );
        mountIndex(); // props.filters are all default → unfiltered
        await flushPromises();

        expect(routerGet).toHaveBeenCalledWith(
            '/users',
            expect.objectContaining({ q: 'saved-term' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('shows a Clear control only when a filter is active, and clears it', async () => {
        sessionStorage.setItem(
            'tableFilters:users',
            JSON.stringify({ q: 'dana' }),
        );
        const wrapper = mountIndex();
        await flushPromises();
        vi.clearAllMocks();

        const clearBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('Clear filters'));
        expect(clearBtn).toBeTruthy();

        await clearBtn!.trigger('click');
        await flushPromises();

        // Clearing re-queries unfiltered and drops the session entry.
        expect(routerGet).toHaveBeenCalledWith(
            '/users',
            expect.objectContaining({ q: undefined }),
            expect.objectContaining({ preserveState: true }),
        );
        expect(sessionStorage.getItem('tableFilters:users')).toBeNull();
    });

    it('has no Apply button — filters apply live', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        expect(
            wrapper.findAll('button').some((b) => b.text().trim() === 'Apply'),
        ).toBe(false);
    });

    it('applies the search filter as you type, debounced', async () => {
        const wrapper = mountIndex();
        await flushPromises();

        vi.useFakeTimers();
        const search = wrapper.find(
            'input[placeholder="Search name, email, title, dept, location, emp #"]',
        );
        await search.setValue('dana');

        // Not yet — still within the debounce window.
        expect(routerGet).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();

        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenLastCalledWith(
            '/users',
            expect.objectContaining({ q: 'dana' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('reloads the users list after a bulk add completes', async () => {
        (axios.get as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { department: [], location: [], job_title: [] },
        });

        const wrapper = mount(UsersIndex, {
            props: {
                users,
                filters: {
                    q: '',
                    role: '',
                    include_disabled: false,
                    tags: [],
                    tags_mode: 'and',
                },
                can_create: true,
            },
            global: {
                stubs: {
                    TagFilter: true,
                    TagsListCell: true,
                    UserFormModal: true,
                    UsersBulkAddGrid: true,
                },
            },
        });
        await flushPromises();

        const bulkBtn = wrapper
            .findAll('button')
            .find((b) => b.text() === 'Bulk add')!;
        await bulkBtn.trigger('click');
        await flushPromises();

        const grid = wrapper.findComponent(UsersBulkAddGrid);
        expect(grid.exists()).toBe(true);

        grid.vm.$emit('done');
        await flushPromises();

        expect(routerReload).toHaveBeenCalledWith({ only: ['users'] });
    });
});
