import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import TableColumnsMenu from '@/components/TableColumnsMenu.vue';

const columns = [
    { key: 'name', label: 'Name', visible: true, sortable: true },
    { key: 'email', label: 'Email', visible: false, sortable: true },
    { key: 'role', label: 'Role', visible: true, sortable: false },
];

async function openMenu() {
    const wrapper = mount(TableColumnsMenu, { props: { columns } });
    // First button is the "Columns" trigger.
    await wrapper.find('button').trigger('click');

    return wrapper;
}

describe('TableColumnsMenu', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('lists a checkbox + label per column once opened', async () => {
        const wrapper = await openMenu();
        expect(wrapper.findAll('[role="checkbox"]')).toHaveLength(3);
        expect(wrapper.text()).toContain('Name');
        expect(wrapper.text()).toContain('Email');
        expect(wrapper.text()).toContain('Role');
    });

    it('emits toggle with the column key when a checkbox is clicked', async () => {
        const wrapper = await openMenu();
        // email is the 2nd checkbox.
        await wrapper.findAll('[role="checkbox"]')[1].trigger('click');
        expect(
            wrapper.emitted('toggle')?.some((e) => e[0] === 'email'),
        ).toBe(true);
    });

    it('emits move on the reorder buttons', async () => {
        const wrapper = await openMenu();
        const right = wrapper.findAll('button[aria-label="Move right"]');
        await right[0].trigger('click'); // move 'name' right
        expect(
            wrapper
                .emitted('move')
                ?.some((e) => e[0] === 'name' && e[1] === 'right'),
        ).toBe(true);
    });

    it('disables move-left on the first column and move-right on the last', async () => {
        const wrapper = await openMenu();
        const left = wrapper.findAll('button[aria-label="Move left"]');
        const right = wrapper.findAll('button[aria-label="Move right"]');
        expect(left[0].attributes('disabled')).toBeDefined();
        expect(right[right.length - 1].attributes('disabled')).toBeDefined();
    });
});
