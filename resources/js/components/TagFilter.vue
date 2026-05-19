<script setup lang="ts">
/*
 * Tag-based list filter, designed for the Tags-column header on list
 * pages. Layout:
 *
 *   [+]  [chip]  [chip]  [chip]                    [mode]
 *
 * - `+` is TagPickerPopover — opens the available-tags grid.
 * - Chips are clickable TagPills (size=sm); click removes (no
 *   confirm — list filtering is reversible at zero cost).
 * - Mode toggle cycles `&` (AND, must have all) → `||` (OR, any) →
 *   `!` (NONE, exclude). Hidden in the empty state.
 *
 * Pure presentation. Two v-models (`tag-ids` + `mode`) so the host
 * owns the state and wires it to a query param.
 *
 * Pattern adapted from another app's TagFilter.vue.
 */

import { computed } from 'vue';
import TagPickerPopover from '@/components/TagPickerPopover.vue';
import TagPill from '@/components/TagPill.vue';
import { useTagsStore, type TagRow } from '@/stores/tags';

export type TagFilterMode = 'and' | 'or' | 'not';

const props = withDefaults(
    defineProps<{
        tagIds: string[];
        mode: TagFilterMode;
        /** Empty-state hint shown when no filter tags are selected. */
        placeholder?: string;
    }>(),
    { placeholder: '' },
);

const emit = defineEmits<{
    (e: 'update:tagIds', ids: string[]): void;
    (e: 'update:mode', mode: TagFilterMode): void;
}>();

const store = useTagsStore();

// Picker shows only tags not already filtered — no point surfacing
// them as a "select me" option that would no-op.
const availableTags = computed<TagRow[]>(() => {
    const selected = new Set(props.tagIds);
    return store.library.filter((t) => !selected.has(t.id));
});

const selectedTags = computed<TagRow[]>(() =>
    props.tagIds
        .map((id) => store.libraryById(id))
        .filter((t): t is TagRow => t !== undefined),
);

function onSelect(tagId: string): void {
    if (props.tagIds.includes(tagId)) return;
    emit('update:tagIds', [...props.tagIds, tagId]);
}

function onRemove(tagId: string): void {
    emit('update:tagIds', props.tagIds.filter((id) => id !== tagId));
}

const MODE_GLYPH: Record<TagFilterMode, string> = {
    and: '&',
    or:  '||',
    not: '!',
};
const MODE_TITLE: Record<TagFilterMode, string> = {
    and: 'AND — must have every selected tag',
    or:  'OR — must have any selected tag',
    not: 'NONE — must have none of the selected tags',
};
const NEXT_MODE: Record<TagFilterMode, TagFilterMode> = {
    and: 'or',
    or:  'not',
    not: 'and',
};

function cycleMode(): void {
    emit('update:mode', NEXT_MODE[props.mode]);
}
</script>

<template>
    <div class="inline-flex flex-wrap items-center gap-1.5 align-middle">
        <TagPickerPopover :available-tags="availableTags" @select="onSelect" />

        <span v-if="tagIds.length === 0" class="text-xs italic text-muted-foreground">
            {{ placeholder }}
        </span>

        <button
            v-for="tag in selectedTags"
            :key="tag.id"
            type="button"
            class="inline-flex cursor-pointer items-center border-0 bg-transparent p-0"
            :title="`Remove ${tag.name} from filter`"
            @click="onRemove(tag.id)"
        >
            <TagPill :tag="tag" size="sm" />
        </button>

        <button
            v-if="tagIds.length > 0"
            type="button"
            class="ml-1 inline-flex h-5 min-w-5 cursor-pointer items-center justify-center rounded px-1.5 font-mono text-xs font-bold leading-none text-muted-foreground hover:bg-muted/50 hover:text-foreground"
            :title="MODE_TITLE[mode]"
            @click="cycleMode"
        >{{ MODE_GLYPH[mode] }}</button>
    </div>
</template>
