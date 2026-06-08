import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TrainingAssignmentPill from '@/components/TrainingAssignmentPill.vue';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

function ta(overrides: Partial<TrainingAssignmentRow> = {}): TrainingAssignmentRow {
    return {
        id: 'ta-1',
        user_id: 'u1',
        training_id: 't1',
        name: 'Fall Protection',
        expires_at: null,
        last_completed_at: null,
        active_sources: [],
        can_delete: true,
        ...overrides,
    };
}

describe('TrainingAssignmentPill', () => {
    it('renders the training name', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta({ name: 'Confined Space Entry' }) },
        });
        expect(wrapper.text()).toContain('Confined Space Entry');
    });

    it('is a button element', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta() },
        });
        expect(wrapper.element.tagName).toBe('BUTTON');
    });

    it('emits click when the button is clicked', async () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta() },
        });
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    it('shows the expiry date when expires_at is set', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta({ expires_at: '2027-06-01' }) },
        });
        expect(wrapper.text()).toContain('2027-06-01');
    });

    it('applies expired styling when expires_at is in the past', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta({ expires_at: '2020-01-01' }) },
        });
        // The root button should carry the expired variant classes.
        expect(wrapper.classes().join(' ')).toMatch(/neutral|muted|line-through|expired/);
    });
});
