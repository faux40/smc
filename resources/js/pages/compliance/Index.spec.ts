import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComplianceIndex from '@/pages/compliance/Index.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('@/routes/users', () => ({
    show: (id: string) => ({ url: `/users/${id}` }),
}));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };

function row(name: string, overrides = {}) {
    return {
        id: name,
        name,
        total: 5,
        counts: { overdue: 2, due_soon: 1, not_started: 1, current: 1, as_needed: 0 },
        ...overrides,
    };
}

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === '/api/compliance/by-training') {
            return Promise.resolve({ data: { data: [row('Fall Protection')], meta: META } });
        }
        if (url === '/api/compliance/by-requirement') {
            return Promise.resolve({ data: { data: [row('OSHA General')], meta: META } });
        }
        if (url === '/api/compliance/not-required') {
            return Promise.resolve({ data: { data: [row('CPR')], meta: META } });
        }
        if (url.endsWith('/users')) {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            user_id: 'u1',
                            name: 'Lovelace, Ada',
                            status: 'overdue',
                            expires_at: '2026-01-01',
                            last_completed_at: '2025-01-01',
                        },
                    ],
                    meta: META,
                },
            });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

/** Params of GETs to a given rollup endpoint, in call order. */
function paramsFor(url: string): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === url)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountPage() {
    const wrapper = mount(ComplianceIndex);
    await flushPromises();

    return wrapper;
}

describe('compliance/Index', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('loads the by-training rollup on mount', async () => {
        const wrapper = await mountPage();

        expect(paramsFor('/api/compliance/by-training')[0]).toMatchObject({
            sort: 'overdue',
            dir: 'desc',
        });
        expect(wrapper.text()).toContain('Fall Protection');
    });

    it('switches to the by-requirement rollup when that tab is clicked', async () => {
        const wrapper = await mountPage();

        await wrapper.find('[data-testid="compliance-tab-requirement"]').trigger('click');
        await flushPromises();

        expect(paramsFor('/api/compliance/by-requirement').length).toBeGreaterThan(0);
        expect(wrapper.text()).toContain('OSHA General');
    });

    it('re-fetches with the chosen sort key when a header is clicked', async () => {
        const wrapper = await mountPage();

        const header = wrapper
            .findAll('thead button')
            .find((b) => b.text().includes('Due soon'));
        await header!.trigger('click');
        await flushPromises();

        expect(paramsFor('/api/compliance/by-training').at(-1)).toMatchObject({
            sort: 'due_soon',
        });
    });

    it('shows the not-required tab with its two statuses and a detail link', async () => {
        const wrapper = await mountPage();

        await wrapper
            .find('[data-testid="compliance-tab-not_required"]')
            .trigger('click');
        await flushPromises();

        expect(paramsFor('/api/compliance/not-required').length).toBeGreaterThan(0);
        expect(wrapper.text()).toContain('CPR');

        // Only the two not-required statuses — not the 5 compliance buckets.
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers.some((h) => h.includes('Taken but Expired'))).toBe(true);
        expect(headers.some((h) => h.includes('Overdue'))).toBe(false);
        expect(headers.some((h) => h.includes('Not started'))).toBe(false);

        // No inline drill-down; the name links to the detail screen.
        expect(wrapper.find('[data-testid="drilldown-CPR"]').exists()).toBe(false);
        const link = wrapper.find('tbody a[href="/compliance/not-required/CPR"]');
        expect(link.exists()).toBe(true);
    });

    it('links each tab row to its detail screen (no inline drill-down)', async () => {
        const wrapper = await mountPage();
        // By training.
        expect(
            wrapper.find('tbody a[href="/compliance/training/Fall Protection"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-testid="drilldown-Fall Protection"]').exists()).toBe(false);

        // By requirement.
        await wrapper.find('[data-testid="compliance-tab-requirement"]').trigger('click');
        await flushPromises();
        expect(
            wrapper.find('tbody a[href="/compliance/requirement/OSHA General"]').exists(),
        ).toBe(true);
    });
});
