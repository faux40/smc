import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import NameCheckModal from '@/pages/classes/Partials/NameCheckModal.vue';

async function openModal(props: Record<string, unknown> = {}) {
    const wrapper = mount(NameCheckModal, {
        props: { open: false, classId: 'c1', completed: false, ...props },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const csvHref = (): string | null =>
    document.body
        .querySelector('[data-testid="name-check-csv"]')!
        .getAttribute('href');

async function toggle(key: string): Promise<void> {
    document.body
        .querySelector<HTMLButtonElement>(`[data-testid="column-toggle-${key}"]`)!
        .click();
    await flushPromises();
}

describe('NameCheckModal', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('exports the name column alone by default', async () => {
        await openModal();

        expect(csvHref()).toBe('/api/classes/c1/name-check?format=csv');
    });

    it('adds a ticked column to the export', async () => {
        await openModal();
        await toggle('job_title');

        expect(csvHref()).toBe(
            '/api/classes/c1/name-check?columns%5B%5D=job_title&format=csv',
        );
    });

    /*
     * The sheet exists to proof-read names, so the name column is not
     * something you can switch off — the control is rendered locked rather
     * than hidden, so it reads as "always included" instead of missing.
     */
    it('will not let the name column be unticked', async () => {
        await openModal();
        await toggle('full_name');

        expect(csvHref()).toBe('/api/classes/c1/name-check?format=csv');
    });

    it('keeps columns in catalog order regardless of ticking order', async () => {
        await openModal();
        await toggle('location');
        await toggle('job_title');

        expect(csvHref()).toBe(
            '/api/classes/c1/name-check?columns%5B%5D=job_title&columns%5B%5D=location&format=csv',
        );
    });

    it('asks the parent to open the PDF with the same columns', async () => {
        const wrapper = await openModal();
        await toggle('department');

        document.body
            .querySelector<HTMLButtonElement>('[data-testid="name-check-pdf"]')!
            .click();
        await flushPromises();

        expect(wrapper.emitted('view')).toBeTruthy();
        expect(wrapper.emitted('view')![0][0]).toEqual([
            'full_name',
            'department',
        ]);
    });

    it('says who will be listed, and it depends on the class being closed', async () => {
        const open = await openModal({ completed: false });
        expect(document.body.textContent).toContain('everyone on the roster');
        open.unmount();

        document.body.innerHTML = '';
        await openModal({ completed: true });
        expect(document.body.textContent).toContain('awarded credit');
    });
});
