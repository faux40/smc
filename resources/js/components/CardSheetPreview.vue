<script setup lang="ts">
/*
 * A sheet of card stock, drawn to scale from its geometry.
 *
 * Two jobs, one drawing: the stock editor uses it to show what a typed grid
 * actually looks like, and the print modal uses it to *pick* the cell a partial
 * sheet resumes at — clicking the cell you can see beats typing its number and
 * hoping the numbering runs the way you assumed.
 *
 * Points throughout (the API's unit); the SVG viewBox does the scaling, so
 * nothing here converts anything.
 */
import { computed } from 'vue';
import { cellRects, perSheet } from '@/lib/cardGeometry';
import type { CardGrid } from '@/lib/cardGeometry';

const props = withDefaults(
    defineProps<{
        grid: CardGrid;
        /** Clickable + keyboard-reachable cells; off for a plain preview. */
        selectable?: boolean;
        /** 1-based cell the cards start at; earlier cells read as used. */
        selected?: number | null;
    }>(),
    { selectable: false, selected: null },
);

const emit = defineEmits<{ (e: 'select', cell: number): void }>();

/**
 * A grid this large is someone still typing, not a real stock — thousands of
 * rects would stall the editor mid-keystroke. The server's own limit is far
 * above any purchased sheet, so this cap is only ever hit by a typo.
 */
const MAX_CELLS = 400;

const count = computed(() => perSheet(props.grid));

const cells = computed(() =>
    count.value > 0 && count.value <= MAX_CELLS ? cellRects(props.grid) : [],
);

/** 'used' = skipped by the start cell, 'start' = where printing resumes. */
function stateOf(index: number): 'used' | 'start' | 'free' {
    if (props.selected === null) {
        return 'free';
    }

    const oneBased = index + 1;

    if (oneBased < props.selected) {
        return 'used';
    }

    return oneBased === props.selected ? 'start' : 'free';
}

const FILLS: Record<string, string> = {
    used: '#e5e7eb',
    start: '#4338ca',
    free: '#e0e7ff',
};

function choose(index: number): void {
    if (props.selectable) {
        emit('select', index + 1);
    }
}

function onKey(event: KeyboardEvent, index: number): void {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(index);
    }
}
</script>

<template>
    <svg
        data-testid="sheet-preview"
        class="w-full rounded border border-border bg-white"
        :viewBox="`0 0 ${grid.page_width} ${grid.page_height}`"
        preserveAspectRatio="xMidYMin meet"
    >
        <rect
            v-for="(cell, i) in cells"
            :key="i"
            data-testid="preview-cell"
            :data-state="stateOf(i)"
            :x="cell.x"
            :y="cell.y"
            :width="cell.width"
            :height="cell.height"
            :fill="FILLS[stateOf(i)]"
            stroke="#4338ca"
            stroke-width="1"
            :class="selectable ? 'cursor-pointer' : undefined"
            :role="selectable ? 'button' : undefined"
            :tabindex="selectable ? 0 : undefined"
            :aria-label="selectable ? `Cell ${i + 1} of ${count}` : undefined"
            @click="choose(i)"
            @keydown="onKey($event, i)"
        />
    </svg>
</template>
