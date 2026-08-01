import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CertEditor from './CertEditor.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { org: { name: 'Acme Safety Co.' } } }),
}));

describe('CertEditor', () => {
    beforeEach(() => setActivePinia(createPinia()));

    it('two-way binds title and text and previews them live', async () => {
        const wrapper = mount(CertEditor, {
            props: { title: '', text: '' },
        });

        await wrapper.get('#cert_title').setValue('FP Authorized');
        await wrapper.get('#cert_text').setValue('Satisfies **Cal/OSHA**');

        // Emitted back to the parent (v-model:title / v-model:text).
        expect(wrapper.emitted('update:title')?.at(-1)).toEqual([
            'FP Authorized',
        ]);
        expect(wrapper.emitted('update:text')?.at(-1)).toEqual([
            'Satisfies **Cal/OSHA**',
        ]);

        // Live preview reflects the edits + the org name from page props.
        const preview = wrapper.get('[data-testid="cert-preview"]');
        expect(preview.text()).toContain('FP Authorized');
        expect(preview.html()).toContain('<strong>Cal/OSHA</strong>');
        expect(preview.text()).toContain('Acme Safety Co.');
    });

    it('gives the preview column a definite width, not shrink-to-fit', () => {
        /*
         * Regression: the column was `shrink-0` with no width. That sizes to
         * content, and the preview inside is `w-full`, which then resolves
         * against that same content width — collapsing an 11:8.5 sheet to the
         * width of its "Preview / Enlarge" header (~110px) while its own
         * max-width appeared to be doing the work. A width class here is what
         * makes the percentage real; happy-dom does no layout, so the class is
         * what can be checked.
         */
        const wrapper = mount(CertEditor, { props: { title: '', text: '' } });
        const classes = wrapper
            .get('[data-testid="cert-preview-column"]')
            .classes();

        expect(classes.some((c) => /^lg:w-/.test(c))).toBe(true);
    });

    it('labels the fields as the SMC certificate, not a custom card', async () => {
        const wrapper = mount(CertEditor, { props: { title: '', text: '' } });

        // "SMC" prefix keeps the built-in certificate distinct from an
        // uploaded custom card template (custom-certs work).
        const labels = wrapper.findAll('label').map((l) => l.text());
        expect(labels).toContain('SMC Certificate title');
        expect(labels).toContain('SMC Certificate text');
    });
});
