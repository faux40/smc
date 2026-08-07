import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MultiSelectDropdown from '@/components/MultiSelectDropdown.vue';

/*
 * Form-flavored dropdown multi-select: the CLOSED trigger shows the chosen
 * options as removable chips (or a placeholder when none), the open panel is
 * a searchable checkbox list. Sibling of MultiSelectFilter, which stays
 * filter-flavored ("All x" / "N x" trigger, mode toggle).
 */

const OPTIONS = [
    { id: 'a', label: 'Comp Person Initial' },
    { id: 'b', label: 'Comp Person Refresher' },
    { id: 'c', label: 'Trainer' },
];

// Popover portals are not what these specs pin — flatten them so trigger and
// panel are both queryable in one wrapper.
const STUBS = {
    Popover: { template: '<div><slot /></div>' },
    PopoverTrigger: { template: '<div><slot /></div>' },
    PopoverContent: { template: '<div><slot /></div>' },
};

function mountIt(props: Record<string, unknown> = {}) {
    return mount(MultiSelectDropdown, {
        props: {
            options: OPTIONS,
            modelValue: [],
            placeholder: 'None — any training can be picked',
            ...props,
        },
        global: { stubs: STUBS },
    });
}

describe('MultiSelectDropdown', () => {
    it('shows the placeholder when nothing is selected', () => {
        const wrapper = mountIt();
        expect(wrapper.text()).toContain('None — any training can be picked');
    });

    it('shows ONLY the selected options as chips when closed', () => {
        const wrapper = mountIt({ modelValue: ['a', 'c'] });

        const trigger = wrapper.get('[data-slot="trigger"]');
        expect(trigger.text()).toContain('Comp Person Initial');
        expect(trigger.text()).toContain('Trainer');
        expect(trigger.text()).not.toContain('Comp Person Refresher');
    });

    it('toggles an option through the checkbox row', async () => {
        const wrapper = mountIt({ modelValue: ['a'] });

        const rows = wrapper.findAll('[data-slot="option"]');
        await rows[1].trigger('click'); // add b

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            ['a', 'b'],
        ]);

        await rows[0].trigger('click'); // remove a
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([[]]);
    });

    it('removes a selection from its chip without opening the panel', async () => {
        const wrapper = mountIt({ modelValue: ['a', 'b'] });

        await wrapper.get('[data-slot="chip-remove-a"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([['b']]);
    });

    it('filters the option list by search', async () => {
        const wrapper = mountIt();

        await wrapper.get('input[type="search"]').setValue('refresher');

        const rows = wrapper.findAll('[data-slot="option"]');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Comp Person Refresher');
    });

    it('drops stale ids silently — a selected id no longer offered renders no chip', () => {
        // The picker excludes loop-makers; a row selected before an exclusion
        // kicked in must not render an undefined-labeled chip.
        const wrapper = mountIt({ modelValue: ['a', 'gone'] });

        const trigger = wrapper.get('[data-slot="trigger"]');
        expect(trigger.text()).toContain('Comp Person Initial');
        expect(trigger.text()).not.toContain('undefined');
    });
});
