import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TrainingMultiSelect from '@/pages/classes/Partials/TrainingMultiSelect.vue';
import type { TrainingRow } from '@/stores/trainings';

function training(id: string, name: string): TrainingRow {
    return {
        id,
        name,
        nickname: null,
        description: null,
        initial_only: false,
        repeating: true,
        std_freq_id: null,
        std_freq_name: null,
        as_needed: false,
        default_hours: null,
        cert_title: null,
        cert_text: null,
        cert_code: null,
        default_trainer: null,
        default_location: null,
        default_address: null,
        can_edit: true,
        can_delete: true,
    };
}

const trainings = [training('t1', 'Fall Protection'), training('t2', 'First Aid')];

describe('TrainingMultiSelect', () => {
    it('renders a checkbox per training', () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: [] },
        });

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Fall Protection');
        expect(wrapper.text()).toContain('First Aid');
    });

    it('reflects the bound selection as checked', () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: ['t2'] },
        });

        const boxes = wrapper.findAll<HTMLInputElement>(
            'input[type="checkbox"]',
        );
        expect(boxes[0].element.checked).toBe(false);
        expect(boxes[1].element.checked).toBe(true);
    });

    it('emits the id added on check', async () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: [] },
        });

        await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['t1']]);
    });

    it('emits the id removed on uncheck', async () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: ['t1', 't2'] },
        });

        await wrapper.findAll('input[type="checkbox"]')[0].setValue(false);

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['t2']]);
    });

    it('shows an empty state when there are no trainings', () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings: [], modelValue: [] },
        });

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(wrapper.text()).toContain('No trainings');
    });

    it('shows a search input and filters the list by name', async () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: [] },
        });

        const search = wrapper.find('input[type="text"]');
        expect(search.exists()).toBe(true);

        await search.setValue('first');

        // "First Aid" matches, "Fall Protection" does not.
        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect(boxes).toHaveLength(1);
        expect(wrapper.text()).toContain('First Aid');
        expect(wrapper.text()).not.toContain('Fall Protection');
    });

    it('shows a selected count badge', () => {
        const wrapper = mount(TrainingMultiSelect, {
            props: { trainings, modelValue: ['t1'] },
        });

        expect(wrapper.text()).toContain('1 selected');
    });
});
