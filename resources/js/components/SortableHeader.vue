<script setup lang="ts">
/*
 * Clickable table header with a sort indicator, paired with useTableSort.
 *
 *   <SortableHeader
 *     label="Name"
 *     sort-key="name"
 *     :active-key="sortKey"
 *     :dir="sortDir"
 *     @sort="toggleSort"
 *   />
 */
import { computed } from 'vue';
import type { SortDir } from '@/composables/useTableSort';

const props = withDefaults(
    defineProps<{
        label: string;
        sortKey: string;
        activeKey: string | null;
        dir: SortDir;
        align?: 'left' | 'right';
    }>(),
    { align: 'left' },
);

const emit = defineEmits<{ (e: 'sort', key: string): void }>();

const active = computed(() => props.activeKey === props.sortKey);
const ariaSort = computed(() =>
    active.value ? (props.dir === 'asc' ? 'ascending' : 'descending') : 'none',
);
const indicator = computed(() =>
    active.value ? (props.dir === 'asc' ? '▲' : '▼') : '↕',
);
</script>

<template>
    <th
        class="px-4 py-2 font-medium"
        :class="align === 'right' ? 'text-right' : 'text-left'"
        :aria-sort="ariaSort"
    >
        <button
            type="button"
            class="inline-flex items-center gap-1 hover:text-foreground"
            :class="align === 'right' ? 'flex-row-reverse' : ''"
            @click="emit('sort', sortKey)"
        >
            <span>{{ label }}</span>
            <span
                class="text-xs"
                :class="active ? 'text-foreground' : 'text-muted-foreground/50'"
                aria-hidden="true"
            >
                {{ indicator }}
            </span>
        </button>
    </th>
</template>
