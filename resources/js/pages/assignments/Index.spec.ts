import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AssignmentsIndex from '@/pages/assignments/Index.vue';

const { authUser } = vi.hoisted(() => ({
    authUser: {
        value: { id: 'me', org_id: 'org1' } as Record<string, unknown>,
    },
}));

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: authUser.value } } }),
}));
vi.mock('@/routes/assignments', () => ({ page: () => ({ url: '/assignments' }) }));

const picker = [
    {
        id: 'u1',
        f_name: 'Pat',
        l_name: 'Lee',
        email: null,
        tag_ids: [],
        employee_number: 'EMP-1',
        department: 'Operations',
        location: 'Yard 3',
        job_title: 'Foreman',
        supervisor_id: null,
        supervisor_name: 'Dana Boss',
    },
];

const STUBS = {
    AssignmentPill: true,
    TagsListCell: true,
    TagFilter: true,
    MultiSelectFilter: true,
    FilterModeToggle: true,
    AssignmentFormModal: true,
    BulkAssignmentsModal: true,
    Heading: true,
    AsyncState: { template: '<div><slot /></div>' },
};

async function mountPage() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) =>
        Promise.resolve({ data: url === '/api/users' ? picker : [] }),
    );
    const wrapper = mount(AssignmentsIndex, { global: { stubs: STUBS } });
    await flushPromises();

    return wrapper;
}

describe('assignments/Index — user profile columns', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('hides a column the user has turned off in their preferences', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { assignments: { visible_columns: { location: false } } },
        };
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Location'))).toBe(false);
        expect(headers.some((h) => h.includes('User'))).toBe(true);
    });

    it('renders the new profile column headers', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Job title'))).toBe(true);
        expect(headers.some((h) => h.includes('Employee #'))).toBe(true);
        expect(headers.some((h) => h.includes('Department'))).toBe(true);
        expect(headers.some((h) => h.includes('Location'))).toBe(true);
        expect(headers.some((h) => h.includes('Supervisor'))).toBe(true);
    });

    it('shows each user row\'s profile fields', async () => {
        const wrapper = await mountPage();
        const body = wrapper.find('tbody').text();
        expect(body).toContain('EMP-1');
        expect(body).toContain('Operations');
        expect(body).toContain('Yard 3');
        expect(body).toContain('Foreman');
        expect(body).toContain('Dana Boss');
    });

    it('restores the user\'s saved filters on mount', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { assignments: { filters: { search: 'fall-protection' } } },
        };
        const wrapper = await mountPage();
        const input = wrapper.find('#filter_search');
        expect((input.element as HTMLInputElement).value).toBe('fall-protection');
    });

    it('persists filters to prefs when they change (debounced)', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
        const wrapper = await mountPage();

        vi.useFakeTimers();
        await wrapper.find('#filter_search').setValue('confined-space');
        await vi.advanceTimersByTimeAsync(700);
        vi.useRealTimers();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/me/preferences',
            expect.objectContaining({
                preferences: expect.objectContaining({
                    assignments: expect.objectContaining({
                        filters: expect.objectContaining({
                            search: 'confined-space',
                        }),
                    }),
                }),
            }),
            expect.anything(),
        );
    });
});
