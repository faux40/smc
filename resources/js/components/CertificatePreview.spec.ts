import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import CertificatePreview from './CertificatePreview.vue';

describe('CertificatePreview', () => {
    it('renders the title and the markdown body', () => {
        const wrapper = mount(CertificatePreview, {
            props: {
                orgName: 'Acme Safety Co.',
                certTitle: 'Fall Protection Authorized Person',
                certText: 'Satisfies **Cal/OSHA** requirements',
            },
        });

        expect(wrapper.text()).toContain('Acme Safety Co.');
        expect(wrapper.text()).toContain('Fall Protection Authorized Person');
        expect(wrapper.get('[data-testid="cert-preview"]').html()).toContain(
            '<strong>Cal/OSHA</strong>',
        );
    });

    it('shows placeholders when title/body are empty', () => {
        const wrapper = mount(CertificatePreview, {
            props: { certTitle: '', certText: '' },
        });

        expect(wrapper.text()).toContain('Certificate title');
        expect(wrapper.text()).toContain('Certificate body');
        expect(wrapper.text()).toContain('Sample Student');
    });
});
