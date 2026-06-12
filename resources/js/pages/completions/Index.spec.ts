import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CompletionsIndex from '@/pages/completions/Index.vue';

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
vi.mock('@/routes/completions', () => ({ page: () => '/completions' }));

const USERS = [
    { id: 'u1', f_name: 'Alice', l_name: 'Adams', email: 'a@test.com' },
    { id: 'u2', f_name: 'Bob', l_name: 'Baker', email: 'b@test.com' },
];

// Date order is intentionally reversed vs alphabetical order so sort tests
// can verify that clicking a header actually changes row order:
//   default (user asc, last name): Adams → Baker
//   date asc:                      Baker → Adams  (2026-01-15 < 2026-03-10)
//   date desc:                     Adams → Baker  (2026-03-10 > 2026-01-15)
const COMPLETIONS = [
    {
        id: 'c1',
        user_id: 'u1',
        module_type: 'App\\Models\\Training',
        module_id: 't1',
        training_name: 'Fire Safety',
        completion_date: '2026-03-10',
        expire_date: '2027-03-10',
        hours: 4.5,
        cert_id: 'CERT20260310-001',
        class_training_id: 'ct1',
        class_id: 'cl1',
        class_name: 'June Safety Day',
        rqmt_element_ids: ['e1', 'e2'],
        effective_element_ids: ['e1', 'e2', 'e9'],
        can_edit: true,
        can_delete: true,
        certification_date: null,
        cert_ident: null,
        notes: null,
    },
    {
        id: 'c2',
        user_id: 'u2',
        module_type: 'App\\Models\\Training',
        module_id: 't1',
        training_name: 'Fire Safety',
        completion_date: '2026-01-15',
        expire_date: null,
        hours: null,
        cert_id: null,
        class_training_id: null,
        class_id: null,
        class_name: null,
        rqmt_element_ids: ['e3'],
        effective_element_ids: ['e3'],
        can_edit: false,
        can_delete: false,
        certification_date: null,
        cert_ident: null,
        notes: null,
    },
];

const TRAININGS = [{ id: 't1', name: 'Fire Safety' }];

const STUBS = {
    CompletionFormModal: true,
    Heading: true,
    AsyncState: { template: '<div><slot /></div>' },
    TableColumnsMenu: true,
};

async function mountPage() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/users') return Promise.resolve({ data: USERS });
        if (url === '/api/completions') return Promise.resolve({ data: COMPLETIONS });
        if (url === '/api/trainings') return Promise.resolve({ data: TRAININGS });
        return Promise.resolve({ data: [] });
    });
    const wrapper = mount(CompletionsIndex, { global: { stubs: STUBS } });
    await flushPromises();
    return wrapper;
}

describe('completions/Index — column control, sorting, filter persistence', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('renders all 7 column headers by default', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('User'))).toBe(true);
        expect(headers.some((h) => h.includes('Training'))).toBe(true);
        expect(headers.some((h) => h.includes('Date'))).toBe(true);
        expect(headers.some((h) => h.includes('Expires'))).toBe(true);
        expect(headers.some((h) => h.includes('Hours'))).toBe(true);
        expect(headers.some((h) => h.includes('Source'))).toBe(true);
        expect(headers.some((h) => h.includes('Credits'))).toBe(true);
    });

    it('renders the server-resolved training name, hours, source and effective credits', async () => {
        const wrapper = await mountPage();
        const text = wrapper.text();

        // Name comes straight from the payload — no client-side lookup.
        expect(text).toContain('Fire Safety');
        expect(text).toContain('4.5');

        // Class-issued row links to its class; manual row is labeled.
        const classLink = wrapper.find('a[href="/classes/cl1"]');
        expect(classLink.exists()).toBe(true);
        expect(classLink.text()).toContain('June Safety Day');
        expect(text).toContain('Manual');

        // Credits badge counts EFFECTIVE credits (pivot + module identity).
        expect(text).toContain('3 element(s)');
        expect(text).toContain('1 element(s)');
    });

    it('hides a column when the user has turned it off in preferences', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: {
                completions: { visible_columns: { credits: false } },
            },
        };
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Credits'))).toBe(false);
        expect(headers.some((h) => h.includes('User'))).toBe(true);
    });

    it('sorts rows by date ascending on first header click', async () => {
        const wrapper = await mountPage();
        const dateBtn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Date'));
        await dateBtn!.trigger('click');
        await flushPromises();

        const rows = wrapper.findAll('tbody tr');
        // Baker (2026-01-15) before Adams (2026-03-10)
        expect(rows[0].text()).toContain('Bob');
        expect(rows[1].text()).toContain('Alice');
    });

    it('reverses sort direction on second click of the same header', async () => {
        const wrapper = await mountPage();
        const dateBtn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Date'));
        await dateBtn!.trigger('click');
        await dateBtn!.trigger('click');
        await flushPromises();

        const rows = wrapper.findAll('tbody tr');
        // Adams (2026-03-10) before Baker (2026-01-15)
        expect(rows[0].text()).toContain('Alice');
        expect(rows[1].text()).toContain('Bob');
    });

    it('filters displayed rows by the selected user', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_user').setValue('u1');
        await flushPromises();

        const rows = wrapper.findAll('tbody tr');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Alice');
    });

    it('persists the user filter selection to the prefs store (debounced)', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: {} });
        const wrapper = await mountPage();

        vi.useFakeTimers();
        await wrapper.find('#filter_user').setValue('u2');
        await vi.advanceTimersByTimeAsync(700);
        vi.useRealTimers();

        expect(axios.patch).toHaveBeenCalledWith(
            '/api/me/preferences',
            expect.objectContaining({
                preferences: expect.objectContaining({
                    completions: expect.objectContaining({
                        filters: expect.objectContaining({ user_id: 'u2' }),
                    }),
                }),
            }),
            expect.anything(),
        );
    });

    it('restores the saved user filter from preferences on mount', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: {
                completions: { filters: { user_id: 'u1' } },
            },
        };
        const wrapper = await mountPage();
        const select = wrapper.find('#filter_user');
        expect((select.element as HTMLSelectElement).value).toBe('u1');
    });
});
