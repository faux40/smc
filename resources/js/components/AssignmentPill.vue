<script setup lang="ts">
/*
 * Assignment pill — requirement name + a strip of timing dots.
 *
 * One per (user, requirement) assignment. A requirement groups RqmtElements
 * that each carry their own timing, so the pill can't reduce to a single
 * color; instead it shows one dot per element, colored by that element's
 * timing, summarising the mix at a glance:
 *   - Initial-only → sky
 *   - Repeating    → emerald
 *   - As-needed    → yellow
 *   - none/unset   → neutral
 *
 * Renders as a <button> so it doubles as the row's edit affordance; the
 * parent owns the click (opening AssignmentFormModal). Does no I/O.
 *
 * Tailwind note: each dot color is a complete static class string (keyed by
 * a map, never composed) so the JIT compiler keeps the colors.
 */
import { computed } from 'vue';
import type { ElementTimingSummary } from '@/stores/assignments';

const props = defineProps<{
    label: string;
    summary: ElementTimingSummary;
}>();

type Timing = 'repeating' | 'initial' | 'as_needed' | 'none';

const DOT: Record<Timing, string> = {
    repeating: 'bg-emerald-400 ring-emerald-300 dark:bg-emerald-500',
    initial: 'bg-sky-400 ring-sky-300 dark:bg-sky-500',
    as_needed: 'bg-yellow-400 ring-yellow-300 dark:bg-yellow-500',
    none: 'bg-neutral-300 ring-neutral-300 dark:bg-neutral-500',
};

const WORD: Record<Timing, string> = {
    repeating: 'repeating',
    initial: 'initial-only',
    as_needed: 'as-needed',
    none: 'no timing set',
};

// Fixed display order: repeating, initial-only, as-needed, none.
const ORDER: Timing[] = ['repeating', 'initial', 'as_needed', 'none'];

// Flatten the summary into one dot per element, capped so a huge
// requirement doesn't blow out the row; overflow shows as "+N".
const MAX_DOTS = 8;

const dots = computed<Timing[]>(() => {
    const all: Timing[] = [];

    for (const t of ORDER) {
        for (let i = 0; i < props.summary[t]; i++) {
            all.push(t);
        }
    }

    return all;
});

const visibleDots = computed(() => dots.value.slice(0, MAX_DOTS));
const overflow = computed(() => Math.max(0, dots.value.length - MAX_DOTS));

const title = computed(() => {
    const parts = ORDER.filter((t) => props.summary[t] > 0).map(
        (t) => `${props.summary[t]} ${WORD[t]}`,
    );

    return parts.length > 0
        ? `${props.label} — ${parts.join(', ')}`
        : `${props.label} — no elements`;
});
</script>

<template>
    <button
        type="button"
        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-0.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
        :title="title"
    >
        <span class="truncate">{{ label }}</span>
        <span v-if="dots.length > 0" class="flex shrink-0 items-center gap-0.5">
            <span
                v-for="(t, i) in visibleDots"
                :key="i"
                class="h-2 w-2 rounded-full ring-1 ring-inset"
                :class="DOT[t]"
            />
            <span v-if="overflow > 0" class="text-[10px] text-muted-foreground">
                +{{ overflow }}
            </span>
        </span>
    </button>
</template>
