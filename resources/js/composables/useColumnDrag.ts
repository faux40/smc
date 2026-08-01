import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useListDrag } from '@/composables/useListDrag';
import type { ResolvedColumn } from '@/composables/useTableView';

/*
 * Drag-to-reorder for table column headers.
 *
 * The mechanics live in useListDrag, shared with the stacked form rows in the
 * card-fields editor. What stays here is what's specific to a table: a header
 * is both the thing you grab and the thing you aim at (so the two attribute
 * sets collapse into one v-bind), the highlight styling, and the
 * visible-column filter.
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
 */
export function useColumnDrag(
    allColumns: ComputedRef<ResolvedColumn[]>,
    reorder: (newOrder: string[]) => void,
) {
    const { dragKey, overKey, sourceAttrs, targetAttrs } = useListDrag(
        computed(() => allColumns.value.map((c) => c.key)),
        reorder,
    );

    // Stable for rendering — never reorders during drag to avoid oscillation.
    const previewVisibleColumns = computed(() =>
        allColumns.value.filter((c) => c.visible),
    );

    function dragAttrs(key: string): Record<string, unknown> {
        return {
            ...sourceAttrs(key),
            ...targetAttrs(key),
            class: {
                'cursor-grab': true,
                'opacity-40': dragKey.value === key,
                'ring-2 ring-inset ring-primary':
                    overKey.value === key && dragKey.value !== key,
            },
        };
    }

    return { dragAttrs, dragKey, overKey, previewVisibleColumns };
}
