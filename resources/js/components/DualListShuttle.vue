<script setup lang="ts" generic="Item extends { id: string }">
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Reusable assigned | available two-list shuttle. Items move between the
 * lists via the +/× buttons or drag-and-drop. Each list has its own search
 * box and click-to-sort column headers. The assigned (left) list can render
 * an extra column (e.g. an editable hours input) via the `#extra` slots.
 *
 * Consumers pre-shape items so each `column.key` resolves to a displayable
 * string/number on the item — the component renders `item[key]` directly.
 */
interface Column {
    key: string;
    label: string;
    numeric?: boolean;
}

const props = defineProps<{
    assigned: Item[];
    available: Item[];
    columns: Column[];
    assignedTitle: string;
    availableTitle: string;
    /** Disable all mutation (e.g. a completed, read-only class). */
    disabled?: boolean;
    searchPlaceholder?: string;
    /** Label for the button that reveals the Available list. */
    addLabel?: string;
    /** Show both lists immediately (no reveal/collapse toggle). */
    alwaysExpanded?: boolean;
}>();

const emit = defineEmits<{
    (e: 'assign', item: Item): void;
    (e: 'unassign', item: Item): void;
}>();

type Side = 'assigned' | 'available';
interface SortState {
    key: string | null;
    dir: 'asc' | 'desc';
}

const queries = ref<Record<Side, string>>({ assigned: '', available: '' });
const sorts = ref<Record<Side, SortState>>({
    assigned: { key: null, dir: 'asc' },
    available: { key: null, dir: 'asc' },
});

function cell(item: Item, key: string): string {
    const v = (item as Record<string, unknown>)[key];

    return v === null || v === undefined || v === '' ? '' : String(v);
}

function view(side: Side): Item[] {
    const items = side === 'assigned' ? props.assigned : props.available;
    const q = queries.value[side].trim().toLowerCase();
    const sort = sorts.value[side];

    let rows = items;

    if (q !== '') {
        rows = rows.filter((it) =>
            props.columns.some((c) => cell(it, c.key).toLowerCase().includes(q)),
        );
    }

    if (sort.key) {
        const col = props.columns.find((c) => c.key === sort.key);
        const factor = sort.dir === 'asc' ? 1 : -1;
        rows = [...rows].sort((a, b) => {
            if (col?.numeric) {
                return (
                    factor *
                    (Number(cell(a, sort.key!) || 0) -
                        Number(cell(b, sort.key!) || 0))
                );
            }

            return (
                factor *
                cell(a, sort.key!).localeCompare(cell(b, sort.key!), undefined, {
                    sensitivity: 'base',
                })
            );
        });
    }

    return rows;
}

const assignedView = computed(() => view('assigned'));
const availableView = computed(() => view('available'));

// The Available list is hidden until the user opts in (and never shown when
// disabled). Collapsed → the Assigned list spans full width, natural height.
const showAvailable = ref(false);
const sidesShown = computed<Side[]>(() =>
    (props.alwaysExpanded || showAvailable.value) && !props.disabled
        ? ['assigned', 'available']
        : ['assigned'],
);

function toggleSort(side: Side, key: string): void {
    const s = sorts.value[side];

    if (s.key === key) {
        s.dir = s.dir === 'asc' ? 'desc' : 'asc';
    } else {
        s.key = key;
        s.dir = 'asc';
    }
}

// Drag-and-drop between the two lists.
const dragging = ref<{ id: string; side: Side } | null>(null);

function onDragStart(e: DragEvent, item: Item, side: Side): void {
    if (props.disabled) {
        return;
    }

    dragging.value = { id: item.id, side };
    e.dataTransfer?.setData('text/plain', item.id);

    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
    }
}

function onDrop(targetSide: Side): void {
    const d = dragging.value;
    dragging.value = null;

    if (props.disabled || !d || d.side === targetSide) {
        return;
    }

    const source = d.side === 'assigned' ? props.assigned : props.available;
    const item = source.find((it) => it.id === d.id);

    if (!item) {
        return;
    }

    if (targetSide === 'assigned') {
        emit('assign', item);
    } else {
        emit('unassign', item);
    }
}
</script>

<template>
    <div class="space-y-2">
        <div v-if="!disabled && !alwaysExpanded" class="flex justify-end">
            <Button
                v-if="!showAvailable"
                type="button"
                size="sm"
                variant="outline"
                @click="showAvailable = true"
            >
                <Plus class="size-4" />
                {{ addLabel ?? 'Add' }}
            </Button>
            <Button
                v-else
                type="button"
                size="sm"
                variant="ghost"
                @click="showAvailable = false"
            >
                Done
            </Button>
        </div>

        <div
            class="grid gap-4"
            :class="{ 'md:grid-cols-2': sidesShown.length === 2 }"
        >
            <div
                v-for="side in sidesShown"
                :key="side"
                class="flex flex-col self-start rounded-md border border-border"
                @dragover.prevent
                @drop.prevent="onDrop(side)"
            >
            <div class="border-b border-border p-2">
                <p class="mb-2 text-xs font-semibold text-muted-foreground">
                    {{ side === 'assigned' ? assignedTitle : availableTitle }}
                    ({{
                        (side === 'assigned' ? assignedView : availableView)
                            .length
                    }})
                </p>
                <Input
                    v-model="queries[side]"
                    type="search"
                    :placeholder="searchPlaceholder ?? 'Search…'"
                    class="h-8 text-xs"
                />
            </div>

            <table class="w-full text-sm">
                <thead class="bg-muted/40 text-xs">
                    <tr>
                        <th
                            v-if="side === 'available'"
                            class="w-8 px-2 py-1.5"
                        ></th>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            class="cursor-pointer select-none px-3 py-1.5 text-left font-medium"
                            @click="toggleSort(side, col.key)"
                        >
                            <span class="inline-flex items-center gap-1">
                                {{ col.label }}
                                <ArrowUp
                                    v-if="
                                        sorts[side].key === col.key &&
                                        sorts[side].dir === 'asc'
                                    "
                                    class="size-3"
                                />
                                <ArrowDown
                                    v-else-if="sorts[side].key === col.key"
                                    class="size-3"
                                />
                            </span>
                        </th>
                        <th
                            v-if="side === 'assigned' && $slots['extra-header']"
                            class="px-3 py-1.5 text-left font-medium"
                        >
                            <slot name="extra-header" />
                        </th>
                        <th
                            v-if="side === 'assigned'"
                            class="w-8 px-2 py-1.5"
                        ></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="item in side === 'assigned'
                            ? assignedView
                            : availableView"
                        :key="item.id"
                        :draggable="!disabled"
                        class="hover:bg-muted/30"
                        :class="{ 'cursor-grab': !disabled }"
                        @dragstart="onDragStart($event, item, side)"
                    >
                        <td v-if="side === 'available'" class="px-2 py-1.5">
                            <button
                                v-if="!disabled"
                                type="button"
                                aria-label="Add"
                                class="rounded p-1 text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-900/30"
                                @click="emit('assign', item)"
                            >
                                <Plus class="size-4" />
                            </button>
                        </td>
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-3 py-1.5"
                        >
                            {{ cell(item, col.key) || '—' }}
                        </td>
                        <td
                            v-if="side === 'assigned' && $slots['extra']"
                            class="px-3 py-1.5"
                        >
                            <slot name="extra" :item="item" :side="side" />
                        </td>
                        <td
                            v-if="side === 'assigned'"
                            class="px-2 py-1.5 text-right"
                        >
                            <button
                                v-if="!disabled"
                                type="button"
                                aria-label="Remove"
                                class="rounded p-1 text-destructive hover:bg-destructive/10"
                                @click="emit('unassign', item)"
                            >
                                <X class="size-4" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="(side === 'assigned' ? assignedView : availableView).length === 0">
                        <td
                            :colspan="
                                columns.length +
                                (side === 'assigned' && $slots['extra'] ? 1 : 0) +
                                1
                            "
                            class="px-3 py-4 text-center text-xs text-muted-foreground"
                        >
                            {{
                                side === 'assigned'
                                    ? 'Nothing assigned yet.'
                                    : 'Nothing available.'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
