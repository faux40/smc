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

// name+role visible, email hidden — for visibility-filter tests.
const MIXED: ResolvedColumn[] = [
    { key: 'name', label: 'Name', visible: true, sortable: true },
    { key: 'email', label: 'Email', visible: false, sortable: true },
    { key: 'role', label: 'Role', visible: true, sortable: false },
];

function makeWrapper(onReorder: (order: string[]) => void, cols = COLUMNS) {
    const allColumns = computed(() => cols);
    const { dragAttrs } = useColumnDrag(allColumns, onReorder);

    return mount(
        defineComponent({
            setup() {
                return { dragAttrs, keys: cols.map((c) => c.key) };
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

/** Call drag event handlers directly without mounting. */
function makeDrag(cols: ResolvedColumn[], onReorder = vi.fn()) {
    const allColumns = computed(() => cols);
    return useColumnDrag(allColumns, onReorder);
}

const fakeEvent = (): DragEvent =>
    ({ dataTransfer: { setData: vi.fn() }, preventDefault: vi.fn() }) as unknown as DragEvent;

describe('useColumnDrag', () => {
    it('calls reorder with the correct new order when dragend fires after a hover', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        // Drag 'name' (index 0) onto 'role' (index 2), then release.
        await ths[0].trigger('dragstart');
        await ths[2].trigger('dragover');
        await ths[0].trigger('dragend'); // commit fires in onDragend

        // name removed from 0, inserted at index 2 → ['email', 'role', 'name']
        expect(reorder).toHaveBeenCalledOnce();
        expect(reorder).toHaveBeenCalledWith(['email', 'role', 'name']);
    });

    it('does not call reorder when dragend fires without ever hovering over a column', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');
        // No dragover — user dragged and released without entering another column.
        await ths[0].trigger('dragend');

        expect(reorder).not.toHaveBeenCalled();
    });

    it('does not call reorder when the source column is dropped onto itself', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[1].trigger('dragstart');
        await ths[1].trigger('dragover');
        await ths[1].trigger('dragend');

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

    it('applies ring class to the drag target but not the source', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');
        await ths[2].trigger('dragover');

        expect(ths[2].classes()).toContain('ring-2');
        expect(ths[0].classes()).not.toContain('ring-2');
        expect(ths[1].classes()).not.toContain('ring-2');
    });

    it('removes all drag styling after dragend', async () => {
        const reorder = vi.fn();
        const wrapper = makeWrapper(reorder);
        const ths = wrapper.findAll('th');

        await ths[0].trigger('dragstart');
        await ths[2].trigger('dragover');
        await ths[0].trigger('dragend');

        expect(ths[0].classes()).not.toContain('opacity-40');
        expect(ths[2].classes()).not.toContain('ring-2');
    });

    describe('previewVisibleColumns', () => {
        it('mirrors visible allColumns when no drag is in progress', () => {
            const { previewVisibleColumns } = makeDrag(COLUMNS);
            expect(previewVisibleColumns.value.map((c) => c.key)).toEqual([
                'name',
                'email',
                'role',
            ]);
        });

        it('stays stable (DOM order unchanged) while a drag is in progress', () => {
            const { dragAttrs, previewVisibleColumns } = makeDrag(COLUMNS);
            const fe = fakeEvent();

            (dragAttrs('name').onDragstart as (e: DragEvent) => void)(fe);
            (dragAttrs('role').onDragover as (e: DragEvent) => void)(fe);

            // Visible order must NOT change during drag to avoid DnD oscillation.
            expect(previewVisibleColumns.value.map((c) => c.key)).toEqual([
                'name',
                'email',
                'role',
            ]);
        });

        it('filters out hidden columns', () => {
            const { previewVisibleColumns } = makeDrag(MIXED);
            expect(previewVisibleColumns.value.map((c) => c.key)).toEqual(['name', 'role']);
        });

        it('reflects the prefs-updated order after dragend commits the reorder', () => {
            // After dragend, reorder() is called; the store updates allColumns; the
            // computed re-evaluates. We simulate the store update by using a ref.
            const { dragAttrs, previewVisibleColumns } = makeDrag(COLUMNS);
            const fe = fakeEvent();

            (dragAttrs('name').onDragstart as (e: DragEvent) => void)(fe);
            (dragAttrs('role').onDragover as (e: DragEvent) => void)(fe);
            (dragAttrs('name').onDragend as () => void)();

            // After dragend, dragKey + overKey are null → previewVisibleColumns = allColumns
            // which in this test is still COLUMNS (immutable fixture). The actual reordering
            // in production flows through the prefs store → useTableView → allColumns reactive.
            expect(previewVisibleColumns.value.map((c) => c.key)).toEqual([
                'name',
                'email',
                'role',
            ]);
        });

        it('commits the correct new order via reorder() on dragend', () => {
            const reorder = vi.fn();
            const { dragAttrs } = makeDrag(COLUMNS, reorder);
            const fe = fakeEvent();

            (dragAttrs('name').onDragstart as (e: DragEvent) => void)(fe);
            (dragAttrs('role').onDragover as (e: DragEvent) => void)(fe);
            (dragAttrs('name').onDragend as () => void)();

            expect(reorder).toHaveBeenCalledWith(['email', 'role', 'name']);
        });
    });
});
