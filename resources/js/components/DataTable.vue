<script setup lang="ts" generic="TRow extends object = Record<string, unknown>">
import { computed } from 'vue';
import SortableHeader from '@/components/SortableHeader.vue';
import TableColumnsMenu from '@/components/TableColumnsMenu.vue';
import { useColumnDrag } from '@/composables/useColumnDrag';
import { useTableView } from '@/composables/useTableView';
import type { ColumnDef } from '@/composables/useTableView';
import type { SortDir } from '@/composables/useTableSort';

const props = withDefaults(
    defineProps<{
        viewId: string;
        defaultColumns: ColumnDef[];
        rows: TRow[];
        sortKey?: string | null;
        sortDir?: SortDir;
        rowKey?: (row: TRow) => string;
    }>(),
    {
        sortKey: null,
        sortDir: 'asc',
        rowKey: undefined,
    },
);

const emit = defineEmits<{
    (e: 'sort', key: string): void;
}>();

const { columns, toggle, reorder, reset, resetAll } = useTableView(
    props.viewId,
    props.defaultColumns,
);

const { dragAttrs, previewVisibleColumns } = useColumnDrag(columns, reorder);

const colspanTotal = computed(() => previewVisibleColumns.value.length);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-3">
            <slot name="filters" />
            <TableColumnsMenu
                class="ml-auto"
                :columns="columns"
                @toggle="toggle"
                @reset="reset"
                @reset-all="resetAll"
            />
        </div>

        <div class="overflow-x-auto rounded-md border border-sidebar-border/70 dark:border-sidebar-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <slot name="lead-header" />
                        <SortableHeader
                            v-for="col in previewVisibleColumns"
                            :key="col.key"
                            v-bind="dragAttrs(col.key)"
                            :label="col.label"
                            :sort-key="col.key"
                            :active-key="sortKey ?? null"
                            :dir="sortDir ?? 'asc'"
                            @sort="emit('sort', col.key)"
                        />
                        <slot name="trail-header" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="(row, idx) in rows" :key="rowKey ? rowKey(row) : idx">
                        <slot name="lead-cells" :row="row" />
                        <td
                            v-for="col in previewVisibleColumns"
                            :key="col.key"
                            class="px-4 py-2"
                        >
                            <slot :name="`col-${col.key}`" :col="col" :row="row">
                                {{ (row as Record<string, unknown>)[col.key] ?? '—' }}
                            </slot>
                        </td>
                        <slot name="trail-cells" :row="row" />
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="colspanTotal"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            <slot name="empty">No rows to display.</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
