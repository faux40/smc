import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import TagFilter from '@/components/TagFilter.vue';
import ReportsIndex from '@/pages/reports/Index.vue';
import { usePreferencesStore } from '@/stores/preferences';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    usePage: () => ({ props: { auth: { user: { org_id: 'o1' } } } }),
}));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const COMPLETIONS = '/api/reports/completions';

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === COMPLETIONS) {
            return Promise.resolve({
                data: {
                    data: [
                        { id: 'c1', user_id: 'u1', tag_ids: ['t1'], user: 'Lee, Sam', employee_number: 'EMP-1', department: 'Ops', location: 'Yard', training: 'CPR', completion_date: '2026-02-01', expire_date: '2020-01-01', status: 'Expired', _band: 'expired', hours: 4, class: '—', cert_id: 'CERT-1' },
                    ],
                    meta: META,
                },
            });
        }
        if (url === '/api/tags')
            return Promise.resolve({
                data: [{ id: 't1', name: 'Night shift', color: '#3b82f6' }],
            });
        return Promise.resolve({ data: [] });
    });
}

function params(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === COMPLETIONS)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountPage() {
    const wrapper = mount(ReportsIndex);
    await flushPromises();

    return wrapper;
}

describe('reports/Index — completion report', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        stubAxios();
    });

    it('loads completions on mount and renders a row with identity + status', async () => {
        const wrapper = await mountPage();
        expect(params().length).toBeGreaterThan(0);
        const body = wrapper.find('tbody').text();
        expect(body).toContain('Lee, Sam');
        expect(body).toContain('CPR');
        // Identifying columns + the expiry status badge (from _band) render.
        expect(body).toContain('EMP-1');
        expect(body).toContain('Ops');
        expect(body).toContain('Expired');
    });

    it('renders the user’s tags in the list (read-only, no add/remove controls)', async () => {
        const wrapper = await mountPage();
        const body = wrapper.find('tbody').text();
        expect(body).toContain('Night shift');
        // Read-only: no "Remove …" detach buttons in the tags cell.
        expect(wrapper.find('[aria-label="Remove Night shift"]').exists()).toBe(
            false,
        );
    });

    it('sends training search (q) and user search (user_q), debounced', async () => {
        const wrapper = await mountPage();
        vi.useFakeTimers();
        await wrapper.find('#rep_training').setValue('forklift');
        await wrapper.find('#rep_user').setValue('lee');
        await vi.advanceTimersByTimeAsync(400);
        vi.useRealTimers();
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ q: 'forklift', user_q: 'lee' });
    });

    it('sends the date range immediately', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#rep_from').setValue('2026-01-01');
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ from: '2026-01-01' });
    });

    it('sends the tag filter', async () => {
        const wrapper = await mountPage();
        wrapper.findComponent(TagFilter).vm.$emit('update:tag-ids', ['tag1']);
        await flushPromises();
        expect(params().at(-1)).toMatchObject({ tags: ['tag1'], tags_mode: 'and' });
    });

    it('sends the status filter (multi-select, any-of)', async () => {
        const wrapper = await mountPage();
        wrapper
            .findComponent(MultiSelectFilter)
            .vm.$emit('update:selected', ['expired', 'due_soon']);
        await flushPromises();
        expect(params().at(-1)).toMatchObject({
            statuses: ['expired', 'due_soon'],
        });
    });

    it('includes selected statuses in the export link', async () => {
        const wrapper = await mountPage();
        wrapper
            .findComponent(MultiSelectFilter)
            .vm.$emit('update:selected', ['expired']);
        await flushPromises();
        const href = wrapper
            .find('[data-testid="export-completion-report"]')
            .attributes('href');
        expect(href).toContain('statuses%5B%5D=expired');
    });

    it('export link lists all visible columns in order by default', async () => {
        const wrapper = await mountPage();
        const href = wrapper
            .find('[data-testid="export-completion-report"]')
            .attributes('href');
        // First and last catalog columns both present.
        expect(href).toContain('columns%5B%5D=user');
        expect(href).toContain('columns%5B%5D=tags');
        expect(href).toContain('columns%5B%5D=cert_id');
    });

    it('export link omits a hidden column', async () => {
        const wrapper = await mountPage();
        usePreferencesStore().update('reports-completions', {
            visible_columns: { tags: false },
        });
        await flushPromises();
        const href = wrapper
            .find('[data-testid="export-completion-report"]')
            .attributes('href');
        expect(href).toContain('columns%5B%5D=user');
        expect(href).not.toContain('columns%5B%5D=tags');
    });

    it('builds the export link from the current filters', async () => {
        const wrapper = await mountPage();
        await wrapper.find('#rep_training').setValue('cpr');
        await wrapper.find('#rep_from').setValue('2026-01-01');
        await flushPromises();

        const href = wrapper.find('[data-testid="export-completion-report"]').attributes('href');
        expect(href).toContain('/api/reports/completions/export?');
        expect(href).toContain('q=cpr');
        expect(href).toContain('from=2026-01-01');
    });
});
