import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DualListShuttle from '@/components/DualListShuttle.vue';

interface Row {
    id: string;
    name: string;
    hours: number;
}

const assigned: Row[] = [
    { id: 'a', name: 'Banana', hours: 2 },
    { id: 'b', name: 'Apple', hours: 5 },
];
const available: Row[] = [
    { id: 'c', name: 'Cherry', hours: 1 },
    { id: 'd', name: 'Date', hours: 9 },
];

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'hours', label: 'Hours', numeric: true },
];

function mountShuttle(props = {}) {
    return mount(DualListShuttle, {
        props: {
            assigned,
            available,
            columns,
            assignedTitle: 'Assigned',
            availableTitle: 'Available',
            ...props,
        },
    });
}

function panel(wrapper: ReturnType<typeof mountShuttle>, idx: number) {
    return wrapper.findAll('table')[idx];
}

describe('DualListShuttle', () => {
    it('renders assigned (left) and available (right) rows', () => {
        const w = mountShuttle();
        expect(panel(w, 0).text()).toContain('Banana');
        expect(panel(w, 0).text()).toContain('Apple');
        expect(panel(w, 1).text()).toContain('Cherry');
        expect(panel(w, 1).text()).toContain('Date');
    });

    it('filters a list by its search box', async () => {
        const w = mountShuttle();
        const search = w.findAll('input[type="search"]')[1]; // available side
        await search.setValue('cher');

        const rows = panel(w, 1).findAll('tbody tr');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('Cherry');
    });

    it('sorts a column ascending then descending on header clicks', async () => {
        const w = mountShuttle();
        const nameHeader = panel(w, 0).findAll('th')[0];

        await nameHeader.trigger('click'); // asc
        let names = panel(w, 0)
            .findAll('tbody tr td:first-child')
            .map((td) => td.text());
        expect(names).toEqual(['Apple', 'Banana']);

        await nameHeader.trigger('click'); // desc
        names = panel(w, 0)
            .findAll('tbody tr td:first-child')
            .map((td) => td.text());
        expect(names).toEqual(['Banana', 'Apple']);
    });

    it('emits assign from the available + button', async () => {
        const w = mountShuttle();
        await panel(w, 1).find('button[aria-label="Add"]').trigger('click');
        expect(w.emitted('assign')?.[0]?.[0]).toMatchObject({ id: 'c' });
    });

    it('emits unassign from the assigned × button', async () => {
        const w = mountShuttle();
        await panel(w, 0).find('button[aria-label="Remove"]').trigger('click');
        expect(w.emitted('unassign')?.[0]?.[0]).toMatchObject({ id: 'a' });
    });

    it('hides action buttons when disabled', () => {
        const w = mountShuttle({ disabled: true });
        expect(w.find('button[aria-label="Add"]').exists()).toBe(false);
        expect(w.find('button[aria-label="Remove"]').exists()).toBe(false);
    });

    it('renders the assigned-side extra slot', () => {
        const w = mount(DualListShuttle, {
            props: {
                assigned,
                available,
                columns,
                assignedTitle: 'A',
                availableTitle: 'B',
            },
            slots: {
                'extra-header': 'Hrs',
                extra: '<span class="extra-cell">x</span>',
            },
        });
        // extra cells only on the assigned (left) table
        expect(panel(w, 0).findAll('.extra-cell')).toHaveLength(2);
        expect(panel(w, 1).findAll('.extra-cell')).toHaveLength(0);
    });
});
