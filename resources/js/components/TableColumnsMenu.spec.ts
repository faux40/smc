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
        await wrapper.findAll('[role="checkbox"]')[1].trigger('click');
        expect(
            wrapper.emitted('toggle')?.some((e) => e[0] === 'email'),
        ).toBe(true);
    });

    it('hints that headers are draggable to reorder', async () => {
        const wrapper = await openMenu();
        expect(wrapper.text()).toContain('drag headers to reorder');
    });

    it('emits reset when "Reset this table" is clicked', async () => {
        const wrapper = await openMenu();
        const btns = wrapper.findAll('button[data-action]');
        const resetBtn = btns.find((b) => b.text().includes('Reset this table'));
        expect(resetBtn).toBeDefined();
        await resetBtn!.trigger('click');
        expect(wrapper.emitted('reset')).toBeTruthy();
    });

    it('emits reset-all when "Reset all tables" is clicked', async () => {
        const wrapper = await openMenu();
        const btns = wrapper.findAll('button[data-action]');
        const resetAllBtn = btns.find((b) => b.text().includes('Reset all tables'));
        expect(resetAllBtn).toBeDefined();
        await resetAllBtn!.trigger('click');
        expect(wrapper.emitted('reset-all')).toBeTruthy();
    });
});
