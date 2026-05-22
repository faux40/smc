import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';

describe('ComplianceStatusBadge', () => {
    it('renders the status label', () => {
        const wrapper = mount(ComplianceStatusBadge, {
            props: { status: 'overdue' },
        });

        expect(wrapper.text()).toContain('Overdue');
    });

    it('omits a count when none is given', () => {
        const wrapper = mount(ComplianceStatusBadge, {
            props: { status: 'overdue' },
        });

        expect(wrapper.text()).not.toMatch(/\d/);
    });

    it('shows the count inside the pill when provided', () => {
        const wrapper = mount(ComplianceStatusBadge, {
            props: { status: 'overdue', count: 3 },
        });

        expect(wrapper.text()).toContain('Overdue');
        expect(wrapper.text()).toContain('3');
    });
});
