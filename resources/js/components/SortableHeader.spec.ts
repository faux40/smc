import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SortableHeader from '@/components/SortableHeader.vue';

function mountHeader(props: {
    label: string;
    sortKey: string;
    activeKey: string | null;
    dir: 'asc' | 'desc';
    align?: 'left' | 'right';
}) {
    return mount(SortableHeader, { props });
}

describe('SortableHeader', () => {
    it('renders the label and emits sort with its key on click', async () => {
        const w = mountHeader({
            label: 'Name',
            sortKey: 'name',
            activeKey: null,
            dir: 'asc',
        });
        expect(w.text()).toContain('Name');

        await w.find('button').trigger('click');
        expect(w.emitted('sort')?.[0]).toEqual(['name']);
    });

    it('marks ascending when it is the active column ascending', () => {
        const w = mountHeader({
            label: 'Name',
            sortKey: 'name',
            activeKey: 'name',
            dir: 'asc',
        });
        expect(w.text()).toContain('▲');
        expect(w.find('th').attributes('aria-sort')).toBe('ascending');
    });

    it('marks descending when it is the active column descending', () => {
        const w = mountHeader({
            label: 'Name',
            sortKey: 'name',
            activeKey: 'name',
            dir: 'desc',
        });
        expect(w.text()).toContain('▼');
        expect(w.find('th').attributes('aria-sort')).toBe('descending');
    });

    it('shows a neutral indicator and aria-sort none when inactive', () => {
        const w = mountHeader({
            label: 'Name',
            sortKey: 'name',
            activeKey: 'email',
            dir: 'asc',
        });
        expect(w.text()).toContain('↕');
        expect(w.find('th').attributes('aria-sort')).toBe('none');
    });
});
