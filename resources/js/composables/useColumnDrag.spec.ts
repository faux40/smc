import { mount } from '@vue/test-utils';
import { computed, defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { useColumnDrag } from '@/composables/useColumnDrag';
import type { ResolvedColumn } from '@/composables/useTableView';

const COLUMNS: ResolvedColumn[] = [
    { key: 'name', label: 'Name', visible: true, sortable: true },
    { key: 'email', label: 'Email', visible: true, sortable: true },
    { key: 'role', label: 'Role', visible: true, sortable: false },
];

function makeWrapper(onReorder: (order: string[]) => void) {
    const allColumns = computed(() => COLUMNS);
    const { dragAttrs } = useColumnDrag(allColumns, onReorder);

    return mount(
        defineComponent({
            setup() {
                return { dragAttrs, keys: COLUMNS.map((c) => c.key) };
            },
            template: `
                <table><thead><tr>
                    <th
                        v-for="key in keys"
                        :key="key"
                        v-bind="dragAttrs(key)"
                        :data-key="key"
                    >{{ key }}</th>
                </tr></thead></table>
            `,
        }),
    );
}

describe('useColumnDrag', () => {
    it('calls reorder with correct new order after a complete drag sequence', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        // Drag 'name' (index 0) onto 'role' (index 2)
        await ths[0].trigger('dragstart');
        await ths[2].trigger('dragover');
        await ths[2].trigger('drop');

        // name removed from 0, inserted at index 2 → ['email', 'role', 'name']
        expect(reorder).toHaveBeenCalledOnce();
        expect(reorder).toHaveBeenCalledWith(['email', 'role', 'name']);
    });

    it('does not call reorder when dropped onto the same column', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[1].trigger('dragstart');
        await ths[1].trigger('drop');

        expect(reorder).not.toHaveBeenCalled();
    });

    it('does not call reorder when drag is cancelled via dragend', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');
        await ths[2].trigger('dragover');
        await ths[0].trigger('dragend'); // cancel without dropping

        expect(reorder).not.toHaveBeenCalled();
    });

    it('applies opacity class to the column being dragged', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');

        expect(ths[0].classes()).toContain('opacity-40');
        expect(ths[1].classes()).not.toContain('opacity-40');
    });

    it('removes drag styling after dragend', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');
        await ths[0].trigger('dragend');

        expect(ths[0].classes()).not.toContain('opacity-40');
    });
});
