import { computed, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { ResolvedColumn } from '@/composables/useTableView';

/*
 * Drag-to-reorder for table column headers.
 *
 * Returns:
 *   dragAttrs(key)          — spread via v-bind onto each <th> / SortableHeader
 *   previewVisibleColumns   — stable visible column list (DOM order never changes
 *                             during drag; the ring highlight on the target column
 *                             shows where the dragged column will land)
 *
 *   const { dragAttrs, previewVisibleColumns } = useColumnDrag(columnDefs, reorder);
 *   <SortableHeader v-for="col in previewVisibleColumns" v-bind="dragAttrs(col.key)" ... />
 *   <td v-for="col in previewVisibleColumns" ...>
 *
 * Why stable DOM order: re-ordering DOM elements live during HTML5 DnD causes the
 * drag source element to move under the cursor, which prevents the browser from
 * firing dragover.preventDefault() on the source (guarded), so drop never fires.
 * The reorder is committed in onDragend instead (always fires).
 */
export function useColumnDrag(
    allColumns: ComputedRef<ResolvedColumn[]>,
    reorder: (newOrder: string[]) => void,
) {
    const dragKey = ref<string | null>(null);
    const overKey = ref<string | null>(null);

    // Internal: computes the committed order for onDragend. Not used for rendering.
    const pendingOrder = computed<ResolvedColumn[]>(() => {
        const dk = dragKey.value;
        const ok = overKey.value;
        if (!dk || !ok || dk === ok) return allColumns.value;

        const cols = [...allColumns.value];
        const fromIdx = cols.findIndex((c) => c.key === dk);
        const toIdx = cols.findIndex((c) => c.key === ok);
        if (fromIdx === -1 || toIdx === -1) return allColumns.value;

        const [moved] = cols.splice(fromIdx, 1);
        cols.splice(toIdx, 0, moved);
        return cols;
    });

    // Stable for rendering — never reorders during drag to avoid oscillation.
    const previewVisibleColumns = computed(() =>
        allColumns.value.filter((c) => c.visible),
    );

    function dragAttrs(key: string): Record<string, unknown> {
        return {
            draggable: true,
            class: {
                'cursor-grab': true,
                'opacity-40': dragKey.value === key,
                'ring-2 ring-inset ring-primary': overKey.value === key && dragKey.value !== key,
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
            onDrop(e: DragEvent): void {
                // Prevent the browser's "bounce back" animation.
                e.preventDefault();
            },
            onDragend(): void {
                // Always commit here — onDrop is unreliable when the cursor is over a
                // child element (e.g. the sort button inside <th>) at release time.
                if (dragKey.value && overKey.value && dragKey.value !== overKey.value) {
                    reorder(pendingOrder.value.map((c) => c.key));
                }
                dragKey.value = null;
                overKey.value = null;
            },
        };
    }

    return { dragAttrs, dragKey, overKey, previewVisibleColumns };
}
