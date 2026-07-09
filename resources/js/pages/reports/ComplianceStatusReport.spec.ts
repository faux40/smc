import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import TagFilter from '@/components/TagFilter.vue';
import ComplianceStatusReport from '@/pages/reports/Partials/ComplianceStatusReport.vue';
import { usePreferencesStore } from '@/stores/preferences';

vi.mock('axios');
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { org_id: 'o1' } } } }),
}));

const META = { current_page: 1, last_page: 1, per_page: 25, total: 1 };
const ENDPOINT = '/api/reports/compliance-status';

function stubAxios() {
    (axios.get as ReturnType<typeof vi.fn>).mockImplementation((url: string) => {
        if (url === ENDPOINT) {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            id: 'a1',
                            user_id: 'u1',
                            training_id: 't1',
                            tag_ids: ['tag1'],
                            user: 'Lee, Sam',
                            employee_number: 'EMP-1',
                            department: 'Ops',
                            location: 'Yard',
                            training: 'Forklift',
                            status: 'Overdue',
                            status_key: 'overdue',
                            _band: 'overdue',
                            expires_at: '2020-01-01',
                            days_until_due: '-500',
                            source: 'Site Safety',
                        },
                    ],
                    meta: META,
                },
            });
        }
        if (url === '/api/tags') {
            return Promise.resolve({ data: [] });
        }
        return Promise.resolve({ data: [] });
    });
}

function params(): Array<Record<string, unknown>> {
    return (axios.get as ReturnType<typeof vi.fn>).mock.calls
        .filter((c) => c[0] === ENDPOINT)
        .map((c) => (c[1]?.params ?? {}) as Record<string, unknown>);
}

async function mountReport(props: Record<string, unknown> = {}) {
    const wrapper = mount(ComplianceStatusReport, { props, attachTo: document.body });
    await flushPromises();

    return wrapper;
}

async function exportHref(wrapper: VueWrapper): Promise<string> {
    await wrapper
        .find('[data-testid="open-compliance-grouping-modal"]')
        .trigger('click');
    await flushPromises();

    return document.body
        .querySelector('[data-testid="export-completion-report"]')!
        .getAttribute('href')!;
}

describe('reports — ComplianceStatusReport', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        document.body.innerHTML = '';
        stubAxios();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('fetches the snapshot on mount and renders a row with status + source', async () => {
        const wrapper = await mountReport();
        expect(params().length).toBeGreaterThan(0);
        const body = wrapper.find('tbody').text();
        expect(body).toContain('Lee, Sam');
        expect(body).toContain('Forklift');
        expect(body).toContain('Overdue');
        expect(body).toContain('Site Safety');
        expect(body).toContain('-500');
    });

    it('sends the status multi-select filter', async () => {
        const wrapper = await mountReport();
        wrapper
            .findComponent(MultiSelectFilter)
            .vm.$emit('update:selected', ['overdue', 'not_started']);
        await flushPromises();
        expect(params().at(-1)).toMatchObject({
            statuses: ['overdue', 'not_started'],
        });
    });

    it('sends the tag filter', async () => {
        const wrapper = await mountReport();
        wrapper.findComponent(TagFilter).vm.$emit('update:tag-ids', ['tag1']);
        await flushPromises();
        expect(params().at(-1)).toMatchObject({
            tags: ['tag1'],
            tags_mode: 'and',
        });
    });

    it('scopes the fetch to requirement_id when provided', async () => {
        await mountReport({ requirementId: 'r9' });
        expect(params().at(-1)).toMatchObject({ requirement_id: 'r9' });
    });

    it('export link carries filters, columns, and group_by', async () => {
        const wrapper = await mountReport();
        wrapper
            .findComponent(MultiSelectFilter)
            .vm.$emit('update:selected', ['overdue']);
        await flushPromises();

        // Open the modal and pick a grouping dimension.
        await wrapper
            .find('[data-testid="open-compliance-grouping-modal"]')
            .trigger('click');
        await flushPromises();
        document.body
            .querySelector<HTMLButtonElement>(
                '[data-testid="group-toggle-source"]',
            )!
            .click();
        await flushPromises();

        const href = document.body
            .querySelector('[data-testid="export-completion-report"]')!
            .getAttribute('href')!;
        expect(href).toContain('/api/reports/compliance-status/export?');
        expect(href).toContain('statuses%5B%5D=overdue');
        expect(href).toContain('columns%5B%5D=user');
        expect(href).toContain('columns%5B%5D=source');
        expect(href).toContain('group_by%5B%5D=source');
    });

    it('export link omits a hidden column', async () => {
        const wrapper = await mountReport();
        usePreferencesStore().update('reports-compliance-status', {
            visible_columns: { source: false },
        });
        await flushPromises();
        const href = await exportHref(wrapper);
        expect(href).toContain('columns%5B%5D=user');
        expect(href).not.toContain('columns%5B%5D=source');
    });

    it('scoped export link carries requirement_id', async () => {
        const wrapper = await mountReport({ requirementId: 'r9' });
        const href = await exportHref(wrapper);
        expect(href).toContain('requirement_id=r9');
    });
});
