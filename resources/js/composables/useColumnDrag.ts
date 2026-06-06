import { ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { ResolvedColumn } from '@/composables/useTableView';

/*
 * Drag-to-reorder for table column headers.
 *
 * Returns `dragAttrs(key)` — spread it via v-bind onto each <th> or any
 * component whose root is a <th> (e.g. SortableHeader). Vue's fallthrough
 * attr merging applies draggable, the drag event listeners, and visual state
 * classes directly to the root element with no changes to SortableHeader.
 *
 *   const { dragAttrs } = useColumnDrag(columnDefs, reorderColumn);
 *   // in template:
 *   <SortableHeader v-bind="dragAttrs(col.key)" ... />
 */
export function useColumnDrag(
    allColumns: ComputedRef<ResolvedColumn[]>,
    reorder: (newOrder: string[]) => void,
) {
    const dragKey = ref<string | null>(null);
    const overKey = ref<string | null>(null);

    function dragAttrs(key: string): Record<string, unknown> {
        return {
            draggable: true,
            class: {
                'cursor-grab': true,
                'opacity-40': dragKey.value === key,
                'ring-2 ring-inset ring-primary':
                    overKey.value === key && dragKey.value !== key,
            },
            onDragstart(e: DragEvent): void {
                dragKey.value = key;
                e.dataTransfer?.setData('text/plain', key);
            },
            onDragover(e: DragEvent): void {
                if (!dragKey.value || key === dragKey.value) return;
                e.preventDefault();
                overKey.value = key;
            },
            onDragleave(): void {
                if (overKey.value === key) overKey.value = null;
            },
            onDrop(e: DragEvent): void {
                e.preventDefault();
                if (!dragKey.value || dragKey.value === key) return;
                const order = allColumns.value.map((c) => c.key);
                const fromIdx = order.indexOf(dragKey.value);
                const toIdx = order.indexOf(key);
                order.splice(fromIdx, 1);
                order.splice(toIdx, 0, dragKey.value);
                reorder(order);
                dragKey.value = null;
                overKey.value = null;
            },
            onDragend(): void {
                dragKey.value = null;
                overKey.value = null;
            },
        };
    }

    return { dragAttrs, dragKey, overKey };
}
