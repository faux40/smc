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

// Available is hidden until the user clicks the reveal button (its text is the
// addLabel; the per-row + buttons are icon-only with aria-label="Add").
async function revealAvailable(w: ReturnType<typeof mountShuttle>) {
    const btn = w
        .findAll('button')
        .find((b) => b.text().trim().length > 0 && !b.attributes('aria-label'));
    await btn!.trigger('click');
}

describe('DualListShuttle', () => {
    it('shows only the assigned list until Available is revealed', async () => {
        const w = mountShuttle();
        expect(w.findAll('table')).toHaveLength(1);
        expect(panel(w, 0).text()).toContain('Banana');

        await revealAvailable(w);
        expect(w.findAll('table')).toHaveLength(2);
        expect(panel(w, 1).text()).toContain('Cherry');
    });

    it('filters a list by its search box', async () => {
        const w = mountShuttle();
        await revealAvailable(w);
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
        await revealAvailable(w);
        await panel(w, 1).find('button[aria-label="Add"]').trigger('click');
        expect(w.emitted('assign')?.[0]?.[0]).toMatchObject({ id: 'c' });
    });

    it('emits unassign from the assigned × button', async () => {
        const w = mountShuttle();
        await panel(w, 0).find('button[aria-label="Remove"]').trigger('click');
        expect(w.emitted('unassign')?.[0]?.[0]).toMatchObject({ id: 'a' });
    });

    it('hides action buttons + reveal when disabled', () => {
        const w = mountShuttle({ disabled: true });
        expect(w.find('button[aria-label="Add"]').exists()).toBe(false);
        expect(w.find('button[aria-label="Remove"]').exists()).toBe(false);
        expect(w.findAll('table')).toHaveLength(1); // available can't be opened
    });

    it('renders the extra slot on the assigned list only', async () => {
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
        await revealAvailable(w);
        expect(panel(w, 0).findAll('.extra-cell')).toHaveLength(2);
        expect(panel(w, 1).findAll('.extra-cell')).toHaveLength(0);
    });

    it('stacks the lists (no 2-column grid) when layout="stacked"', () => {
        const split = mountShuttle({ alwaysExpanded: true });
        expect(split.find('.md\\:grid-cols-2').exists()).toBe(true);

        const stacked = mountShuttle({
            alwaysExpanded: true,
            layout: 'stacked',
        });
        // Both lists still render, but never as a side-by-side grid.
        expect(stacked.findAll('table')).toHaveLength(2);
        expect(stacked.find('.md\\:grid-cols-2').exists()).toBe(false);
        // Assigned renders before available (roster on top).
        expect(panel(stacked, 0).text()).toContain('Banana');
        expect(panel(stacked, 1).text()).toContain('Cherry');
    });

    it('renders the available-controls slot under the available search box', () => {
        const w = mount(DualListShuttle, {
            props: {
                assigned,
                available,
                columns,
                assignedTitle: 'A',
                availableTitle: 'B',
                alwaysExpanded: true,
                layout: 'stacked',
            },
            slots: {
                'available-controls':
                    '<div class="avail-filters">filters</div>',
            },
        });
        expect(w.findAll('.avail-filters')).toHaveLength(1);
    });
});
