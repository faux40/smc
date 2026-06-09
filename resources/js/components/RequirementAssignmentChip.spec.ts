import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import type { RequirementAssignmentRow } from '@/stores/requirementAssignments';

function row(overrides: Partial<RequirementAssignmentRow> = {}): RequirementAssignmentRow {
    return { requirement_id: 'r1', requirement_name: 'Fire Safety', user_id: 'u1', ...overrides };
}

describe('RequirementAssignmentChip', () => {
    it('renders the requirement name', () => {
        const wrapper = mount(RequirementAssignmentChip, {
            props: { row: row(), canDelete: false },
        });
        expect(wrapper.text()).toContain('Fire Safety');
    });

    it('hides the remove button when canDelete is false', () => {
        const wrapper = mount(RequirementAssignmentChip, {
            props: { row: row(), canDelete: false },
        });
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('shows the remove button when canDelete is true', () => {
        const wrapper = mount(RequirementAssignmentChip, {
            props: { row: row(), canDelete: true },
        });
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('emits remove when the × button is clicked', async () => {
        const wrapper = mount(RequirementAssignmentChip, {
            props: { row: row({ requirement_id: 'r1', user_id: 'u1' }), canDelete: true },
        });
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('remove')).toHaveLength(1);
    });
});
