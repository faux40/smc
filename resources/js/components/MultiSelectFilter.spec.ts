import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FilterModeToggle from '@/components/FilterModeToggle.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';

const OPTIONS = [
    { id: 'a', label: 'Alpha' },
    { id: 'b', label: 'Beta' },
];

function mountFilter(props: Record<string, unknown> = {}) {
    return mount(MultiSelectFilter, {
        props: {
            options: OPTIONS,
            selected: ['a'],
            mode: 'or',
            label: 'statuses',
            ...props,
        },
    });
}

describe('MultiSelectFilter', () => {
    it('shows the mode toggle by default when something is selected', () => {
        const wrapper = mountFilter();
        expect(wrapper.findComponent(FilterModeToggle).exists()).toBe(true);
    });

    it('hides the mode toggle when show-mode is false (single-valued fields)', () => {
        const wrapper = mountFilter({ showMode: false });
        expect(wrapper.findComponent(FilterModeToggle).exists()).toBe(false);
    });

    it('summarises the selection count on the trigger', () => {
        expect(mountFilter({ selected: [] }).text()).toContain('All statuses');
        expect(mountFilter({ selected: ['a', 'b'] }).text()).toContain(
            '2 statuses',
        );
    });
});
