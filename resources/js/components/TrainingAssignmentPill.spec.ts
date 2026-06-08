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

// A completed-and-current row — real data always has both fields set together.
function completed(overrides: Partial<TrainingAssignmentRow> = {}): TrainingAssignmentRow {
    return ta({ last_completed_at: '2024-01-01', ...overrides });
}

describe('TrainingAssignmentPill', () => {
    it('renders the training name', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta({ name: 'Confined Space Entry' }) },
        });
        expect(wrapper.text()).toContain('Confined Space Entry');
    });

    it('is a button element', () => {
        const wrapper = mount(TrainingAssignmentPill, { props: { row: ta() } });
        expect(wrapper.element.tagName).toBe('BUTTON');
    });

    it('emits click when the button is clicked', async () => {
        const wrapper = mount(TrainingAssignmentPill, { props: { row: ta() } });
        await wrapper.trigger('click');
        expect(wrapper.emitted('click')).toHaveLength(1);
    });

    // -- never completed (null last_completed_at) --------------------------

    it('applies red styling when never completed', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: ta({ last_completed_at: null, expires_at: null }) },
        });
        expect(wrapper.html()).toMatch(/red/);
    });

    // -- expires_at states (completed rows) --------------------------------

    it('shows the expiry date when expires_at is set', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: '2027-06-01' }) },
        });
        expect(wrapper.text()).toContain('2027-06-01');
    });

    it('applies green styling when completed and not expiring soon', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: '2030-01-01' }) },
        });
        expect(wrapper.classes().join(' ')).toMatch(/green/);
    });

    it('applies amber styling when expiring soon', () => {
        const soon = new Date();
        soon.setDate(soon.getDate() + 10);
        const expires = soon.toISOString().slice(0, 10);

        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: expires }), expiringSoonDays: 30 },
        });
        expect(wrapper.classes().join(' ')).toMatch(/amber/);
    });

    it('applies red styling when expires_at is in the past', () => {
        const wrapper = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: '2020-01-01' }) },
        });
        expect(wrapper.classes().join(' ')).toMatch(/red/);
    });

    it('treats a date as expiring when within a custom expiringSoonDays window', () => {
        const soon = new Date();
        soon.setDate(soon.getDate() + 10);
        const expires = soon.toISOString().slice(0, 10);

        const withDefault = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: expires }) },
        });
        const withCustom = mount(TrainingAssignmentPill, {
            props: { row: completed({ expires_at: expires }), expiringSoonDays: 14 },
        });

        expect(withDefault.text()).toContain(expires);
        expect(withCustom.text()).toContain(expires);
    });
});
