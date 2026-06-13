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

// Captured params from each GET /api/completions call (the paged contract).
let completionsParams: Array<Record<string, unknown>> = [];
const lastParams = () => completionsParams.at(-1) ?? {};

function mockAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation(
        (url: string, config?: { params?: Record<string, unknown> }) => {
            if (url === '/api/users') {
                return Promise.resolve({ data: USERS });
            }

            if (url === '/api/trainings') {
                return Promise.resolve({ data: TRAININGS });
            }

            if (url === '/api/completions') {
                completionsParams.push(config?.params ?? {});

                return Promise.resolve({
                    data: {
                        data: COMPLETIONS,
                        meta: {
                            current_page: Number(config?.params?.page ?? 1),
                            last_page: 2,
                            per_page: Number(config?.params?.per_page ?? 25),
                            total: 30,
                        },
                    },
                });
            }

            return Promise.resolve({ data: [] });
        },
    );
}

async function mountPage() {
    mockAxios();
    const wrapper = mount(CompletionsIndex, { global: { stubs: STUBS } });
    await flushPromises();

    return wrapper;
}

describe('completions/Index — server-paged table', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        completionsParams = [];
        authUser.value = { id: 'me', org_id: 'org1' };
    });

    it('renders the column headers (incl. the row-# lead column)', async () => {
        const wrapper = await mountPage();
        const headers = wrapper.findAll('thead th').map((th) => th.text());

        for (const h of [
            'User',
            'Training',
            'Date',
            'Expires',
            'Hours',
            'Source',
            'Credits',
        ]) {
            expect(headers.some((x) => x.includes(h))).toBe(true);
        }

        expect(headers.some((x) => x.trim() === '#')).toBe(true);
    });

    it('renders server-resolved fields, source link, and effective credits', async () => {
        const wrapper = await mountPage();
        const text = wrapper.text();
        expect(text).toContain('Fire Safety');
        expect(text).toContain('4.5');
        const classLink = wrapper.find('a[href="/classes/cl1"]');
        expect(classLink.exists()).toBe(true);
        expect(text).toContain('Manual');
        expect(text).toContain('3 element(s)');
    });

    it('requests page 1 sorted by completion_date desc on mount', async () => {
        await mountPage();
        const p = completionsParams[0];
        expect(p.page).toBe(1);
        expect(p.sort).toBe('completion_date');
        expect(p.dir).toBe('desc');
    });

    it('asks the server for the sort when a sortable header is clicked', async () => {
        const wrapper = await mountPage();
        const dateBtn = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Date'));
        await dateBtn!.trigger('click');
        await flushPromises();
        expect(lastParams().sort).toBe('completion_date');
        expect(lastParams().dir).toBe('asc'); // new direction served by the server

        await dateBtn!.trigger('click');
        await flushPromises();
        expect(lastParams().dir).toBe('desc');
    });

    it('sends user_id to the server when the user filter changes', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#filter_user').setValue('u1');
        await flushPromises();
        expect(lastParams().user_id).toBe('u1');
        expect(lastParams().page).toBe(1);
    });

    it('debounces the search box into a server q', async () => {
        const wrapper = await mountPage();
        vi.useFakeTimers();
        await wrapper.find('#filter_q').setValue('cert');
        await vi.advanceTimersByTimeAsync(300);
        vi.useRealTimers();
        await flushPromises();
        expect(lastParams().q).toBe('cert');
    });

    it('requests the next page from the server', async () => {
        const wrapper = await mountPage();
        await wrapper.find('button[aria-label="Next page"]').trigger('click');
        await flushPromises();
        expect(lastParams().page).toBe(2);
    });

    it('hides a column the user turned off in preferences', async () => {
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

    it('restores the saved user filter and applies it to the first fetch', async () => {
        authUser.value = {
            id: 'me',
            org_id: 'org1',
            preferences: { completions: { filters: { user_id: 'u1' } } },
        };
        const wrapper = await mountPage();
        expect(
            (wrapper.find('#filter_user').element as HTMLSelectElement).value,
        ).toBe('u1');
        expect(completionsParams.some((p) => p.user_id === 'u1')).toBe(true);
    });

    it('persists the user filter to prefs (debounced)', async () => {
        (axios.patch as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {},
        });
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
});
