<script setup lang="ts">
/*
 * Tiny &/||/! mode cycle button shared by list filters.
 *
 *   & (AND, match all) → || (OR, match any) → ! (NONE, exclude)
 *
 * `modes` restricts the cycle (e.g. ['or', 'not'] where AND is degenerate,
 * like a single-valued field). Pure presentation; v-model:mode on the host.
 */
import { computed } from 'vue';

export type FilterMode = 'and' | 'or' | 'not';

const props = withDefaults(
    defineProps<{
        mode: FilterMode;
        modes?: FilterMode[];
    }>(),
    { modes: () => ['and', 'or', 'not'] },
);

const emit = defineEmits<{ (e: 'update:mode', mode: FilterMode): void }>();

const GLYPH: Record<FilterMode, string> = { and: '&', or: '||', not: '!' };
const TITLE: Record<FilterMode, string> = {
    and: 'AND — must match all selected',
    or: 'OR — must match any selected',
    not: 'NONE — must match none of the selected',
};

const glyph = computed(() => GLYPH[props.mode]);
const title = computed(() => TITLE[props.mode]);

function cycle(): void {
    const list = props.modes;
    const i = list.indexOf(props.mode);

    emit('update:mode', list[(i + 1) % list.length]);
}
</script>

<template>
    <button
        type="button"
        class="inline-flex h-6 min-w-6 cursor-pointer items-center justify-center rounded px-1.5 font-mono text-xs leading-none font-bold text-muted-foreground hover:bg-muted/50 hover:text-foreground"
        :title="title"
        @click="cycle"
    >
        {{ glyph }}
    </button>
</template>
