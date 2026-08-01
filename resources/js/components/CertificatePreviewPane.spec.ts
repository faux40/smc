import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import CertificatePreviewPane from '@/components/CertificatePreviewPane.vue';

function mountPane(props: Record<string, unknown> = {}) {
    return mount(CertificatePreviewPane, {
        props: {
            certTitle: 'Fall Protection',
            certText: 'Satisfies **Cal/OSHA** requirements.',
            orgName: 'Acme Safety Co.',
            ...props,
        },
        attachTo: document.body,
    });
}

/** Previews rendered anywhere in the document, thumbnail or dialog. */
const previews = () =>
    Array.from(document.body.querySelectorAll('[data-testid="cert-preview"]'));

describe('CertificatePreviewPane', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('shows a thumbnail that tracks what is being typed', () => {
        const wrapper = mountPane();

        expect(previews()).toHaveLength(1);
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.html()).toContain('<strong>Cal/OSHA</strong>');
        expect(wrapper.text()).toContain('Acme Safety Co.');
    });

    it('caps the thumbnail so it cannot dominate the form', () => {
        // The certificate is 11:8.5, so its height follows its width — the
        // cap is what keeps a live preview from being the tallest thing on
        // the page beside the four fields it is previewing.
        const wrapper = mountPane();

        expect(
            wrapper.get('[data-testid="cert-thumbnail"]').classes().join(' '),
        ).toMatch(/max-w-/);
    });

    it('offers a way to see it full size', () => {
        const wrapper = mountPane();

        expect(wrapper.find('[data-testid="cert-preview-open"]').exists()).toBe(
            true,
        );
    });

    it('opens a second, larger copy on request', async () => {
        const wrapper = mountPane();

        await wrapper.get('[data-testid="cert-preview-open"]').trigger('click');

        // The thumbnail stays put behind the dialog; the dialog's copy is
        // unconstrained, which is the whole point of opening it.
        expect(previews()).toHaveLength(2);
        expect(document.body.textContent).toContain('Fall Protection');
    });

    it('keeps the enlarged copy live while it is open', async () => {
        const wrapper = mountPane();
        await wrapper.get('[data-testid="cert-preview-open"]').trigger('click');

        await wrapper.setProps({ certTitle: 'Forklift Operator' });

        expect(document.body.textContent).toContain('Forklift Operator');
        expect(document.body.textContent).not.toContain('Fall Protection');
    });

    it('passes the sample details through to both copies', async () => {
        // Everything CertificatePreview understands has to survive the trip,
        // or the enlarged copy would quietly show defaults instead.
        const wrapper = mountPane({
            studentName: 'Dana Reed',
            certId: 'CERT20260601-007',
        });
        await wrapper.get('[data-testid="cert-preview-open"]').trigger('click');

        for (const preview of previews()) {
            expect(preview.textContent).toContain('Dana Reed');
            expect(preview.textContent).toContain('CERT20260601-007');
        }
    });

    it('names what it is previewing when told', async () => {
        // A class shows one of these per topic; "Preview" alone would be
        // ambiguous once there are two on screen.
        const wrapper = mountPane({ label: 'First Aid / CPR' });

        expect(
            wrapper.get('[data-testid="cert-preview-open"]').attributes(
                'aria-label',
            ),
        ).toContain('First Aid / CPR');
    });
});
