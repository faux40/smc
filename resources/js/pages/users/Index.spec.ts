import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UsersIndex from '@/pages/users/Index.vue';
import type { UserRow } from '@/stores/users';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: { get: routerGet },
    usePage: () => ({
        props: { auth: { user: { id: 'me', org_id: 'org1' } } },
    }),
}));
vi.mock('@/routes/users', () => ({
    index: () => ({ url: '/users' }),
    show: (id: string) => ({ url: `/users/${id}` }),
}));

function user(overrides: Partial<UserRow>): UserRow {
    return {
        id: 'u',
        name: 'X',
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
        start_date: null,
        end_date: null,
        created_at: null,
        tag_ids: [],
        can_edit: false,
        can_disable: false,
        can_delete: false,
        ...overrides,
    };
}

const users: UserRow[] = [
    user({ id: 'u1', name: 'Zoe Charlie', f_name: 'Zoe', l_name: 'Charlie', department: 'Ops' }),
    user({ id: 'u2', name: 'Amy Adams', f_name: 'Amy', l_name: 'Adams', department: 'Admin' }),
    user({ id: 'u3', name: 'Bob Baker', f_name: 'Bob', l_name: 'Baker', department: null }),
];

function mountIndex() {
    return mount(UsersIndex, {
        props: {
            users,
            filters: { q: '', role: '', include_disabled: false, tags: [], tags_mode: 'and' },
            can_create: false,
        },
        global: {
            stubs: { TagFilter: true, TagsListCell: true, UserFormModal: true },
        },
    });
}

function nameColumn(wrapper: ReturnType<typeof mountIndex>): string[] {
    return wrapper
        .findAll('tbody tr')
        .map((tr) => tr.find('td').text().trim());
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
    });

    it('renders the new profile column headers', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Job title'))).toBe(true);
        expect(headers.some((h) => h.includes('Employee #'))).toBe(true);
        expect(headers.some((h) => h.includes('Department'))).toBe(true);
        expect(headers.some((h) => h.includes('Location'))).toBe(true);
    });

    it('defaults to last-name ascending order', async () => {
        const wrapper = mountIndex();
        await flushPromises();
        // Adams, Baker, Charlie by last name.
        expect(nameColumn(wrapper)).toEqual([
            'Amy Adams',
            'Bob Baker',
            'Zoe Charlie',
        ]);
    });

    it('reorders by department (empties last) when that header is clicked', async () => {
        const wrapper = mountIndex();
        await flushPromises();

        await clickHeader(wrapper, 'Department');
        // Admin (u2), Ops (u1), then null (u3) last.
        expect(nameColumn(wrapper)).toEqual([
            'Amy Adams',
            'Zoe Charlie',
            'Bob Baker',
        ]);
    });
});

describe('users/Index — live filtering', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
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
            'input[placeholder="Search by name or email"]',
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
});
