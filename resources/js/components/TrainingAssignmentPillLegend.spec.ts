import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TrainingAssignmentPillLegend from '@/components/TrainingAssignmentPillLegend.vue';

describe('TrainingAssignmentPillLegend', () => {
    it('renders a red entry for overdue / not started', () => {
        const wrapper = mount(TrainingAssignmentPillLegend);
        expect(wrapper.html()).toMatch(/red/);
        expect(wrapper.text()).toMatch(/overdue|not started/i);
    });

    it('renders an amber entry for expiring soon', () => {
        const wrapper = mount(TrainingAssignmentPillLegend);
        expect(wrapper.html()).toMatch(/amber/);
        expect(wrapper.text()).toMatch(/expiring soon/i);
    });

    it('renders a green entry for current', () => {
        const wrapper = mount(TrainingAssignmentPillLegend);
        expect(wrapper.html()).toMatch(/green/);
        expect(wrapper.text()).toMatch(/current/i);
    });
});
