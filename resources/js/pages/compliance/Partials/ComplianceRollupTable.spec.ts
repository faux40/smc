import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComplianceRollupTable from '@/pages/compliance/Partials/ComplianceRollupTable.vue';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    usePage: () => ({ props: { auth: { user: { org_id: 'org1' } } } }),
}));

const ROWS = [
    {
        id: 't1',
        name: 'CPR',
        total: 3,
        counts: {
            overdue: 1,
            due_soon: 0,
            not_started: 0,
            current: 2,
            as_needed: 0,
        },
    },
];

const STUBS = {
    AsyncState: { template: '<div><slot /></div>' },
    TableColumnsMenu: true,
    ComplianceDrilldown: true,
};

function fetcher() {
    return Promise.resolve({
        data: ROWS,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
}

async function mountTable(props: Record<string, unknown> = {}) {
    const wrapper = mount(ComplianceRollupTable, {
        props: {
            viewId: 'compliance-training',
            nameLabel: 'Training',
            searchPlaceholder: 'Search trainings…',
            fetcher,
            exportDimension: 'training',
            ...props,
        },
        global: { stubs: STUBS },
    });
    await flushPromises();

    return wrapper;
}

describe('ComplianceRollupTable — print link', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const href = (w: Awaited<ReturnType<typeof mountTable>>) =>
        w.get('[data-testid="export-compliance"]').attributes('href');

    it('names the tab it is printing', async () => {
        // One endpoint serves all three tabs, so the dimension is what makes a
        // sheet the *right* sheet — the wrong one would look entirely
        // plausible and roll up completely different numbers.
        const wrapper = await mountTable();

        expect(href(wrapper)).toContain('dimension=training');
    });

    it('carries the tab it was actually mounted for', async () => {
        const wrapper = await mountTable({
            exportDimension: 'not-required',
            countColumns: [
                { key: 'current', label: 'Current' },
                { key: 'expired', label: 'Taken but Expired' },
            ],
            initialSort: 'expired',
        });

        expect(href(wrapper)).toContain('dimension=not-required');
    });

    it('carries the sort the table is showing', async () => {
        const wrapper = await mountTable();

        expect(href(wrapper)).toContain('sort=overdue');
        expect(href(wrapper)).toContain('dir=desc');
    });

    it('carries the visible columns', async () => {
        const wrapper = await mountTable();

        expect(href(wrapper)).toContain('columns%5B%5D=name');
    });

    it('carries the search box once it has a value', async () => {
        const wrapper = await mountTable();

        await wrapper.get('input').setValue('cpr');
        await flushPromises();

        expect(href(wrapper)).toContain('q=cpr');
    });

    it('omits the search when the box is empty', async () => {
        const wrapper = await mountTable();

        expect(href(wrapper)).not.toContain('q=');
    });
});
