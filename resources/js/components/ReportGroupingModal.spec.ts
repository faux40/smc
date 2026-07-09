import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import ReportGroupingModal from '@/components/ReportGroupingModal.vue';

const BASE = '/api/reports/completions/export?q=cpr';

async function openModal(baseHref = BASE) {
    const wrapper = mount(ReportGroupingModal, {
        props: { open: false, baseHref },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const generateHref = (): string | null =>
    document.body
        .querySelector('[data-testid="export-completion-report"]')!
        .getAttribute('href');

const csvHref = (): string | null =>
    document.body
        .querySelector('[data-testid="export-completion-report-csv"]')!
        .getAttribute('href');

// Clicks the option row's checkbox button (toggles selection).
async function toggle(key: string): Promise<void> {
    document.body
        .querySelector<HTMLButtonElement>(
            `[data-testid="group-toggle-${key}"]`,
        )!
        .click();
    await flushPromises();
}

async function move(key: string, dir: 'up' | 'down'): Promise<void> {
    document.body
        .querySelector<HTMLButtonElement>(
            `[data-testid="group-${dir}-${key}"]`,
        )!
        .click();
    await flushPromises();
}

describe('ReportGroupingModal', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('with nothing checked, generates the unchanged base href (no grouping)', async () => {
        await openModal();
        expect(generateHref()).toBe(BASE);
    });

    it('appends checked dimensions as ordered group_by[] params', async () => {
        await openModal();
        await toggle('location');
        await toggle('department');
        const href = generateHref()!;
        expect(href).toContain('group_by%5B%5D=location');
        expect(href).toContain('group_by%5B%5D=department');
        // location was checked first → it precedes department.
        expect(href.indexOf('location')).toBeLessThan(
            href.indexOf('department'),
        );
    });

    it('unchecking removes the dimension from the href', async () => {
        await openModal();
        await toggle('location');
        await toggle('location');
        expect(generateHref()).toBe(BASE);
    });

    it('reordering changes group_by precedence', async () => {
        await openModal();
        await toggle('location');
        await toggle('department');
        // Promote department above location.
        await move('department', 'up');
        const href = generateHref()!;
        expect(href.indexOf('department')).toBeLessThan(
            href.indexOf('location'),
        );
    });

    it('appends with & when the base href already has a query string', async () => {
        await openModal(
            '/api/reports/completions/export?q=cpr&from=2026-01-01',
        );
        await toggle('status');
        expect(generateHref()).toContain('&group_by%5B%5D=status');
    });

    it('resets the selection each time it reopens', async () => {
        const wrapper = await openModal();
        await toggle('user');
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(generateHref()).toBe(BASE);
    });

    it('renders a Download CSV button alongside Generate report', async () => {
        await openModal();
        expect(
            document.body.querySelector(
                '[data-testid="export-completion-report-csv"]',
            ),
        ).not.toBeNull();
    });

    it('the CSV href equals the PDF href with format=csv appended', async () => {
        await openModal();
        expect(csvHref()).toBe(`${BASE}&format=csv`);
    });

    it('the CSV href carries filters, columns, and group_by like the PDF href', async () => {
        await openModal(
            '/api/reports/completions/export?q=cpr&columns%5B%5D=user',
        );
        await toggle('location');
        await toggle('department');

        const pdfHref = generateHref()!;
        expect(csvHref()).toBe(`${pdfHref}&format=csv`);
        expect(csvHref()).toContain('group_by%5B%5D=location');
        expect(csvHref()).toContain('group_by%5B%5D=department');
        expect(csvHref()).toContain('columns%5B%5D=user');
    });

    it('appends format=csv with a ? when the base href has no query string', async () => {
        await openModal('/api/reports/completions/export');
        expect(csvHref()).toBe('/api/reports/completions/export?format=csv');
    });
});
