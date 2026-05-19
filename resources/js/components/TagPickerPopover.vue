<script setup lang="ts">
/*
 * The "+" popover for inline tagging. Trigger is a lime PlusCircle;
 * content is a dense 2-column grid of TagPills showing every tag the
 * parent passes in. Caller filters `availableTags` down to "library
 * minus already-attached" — this component just sorts and renders.
 *
 * Pattern adapted from another app's TagPickerPopover; here it talks
 * to our existing `TagPill` and emits the chosen tag id. No catalog
 * management (create/edit/delete tags lives only on /tags).
 */

import { computed, ref } from 'vue';
import { PlusCircle } from 'lucide-vue-next';
import TagPill from '@/components/TagPill.vue';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { TagRow } from '@/stores/tags';

const props = withDefaults(
    defineProps<{
        availableTags: TagRow[];
        /** When true, the popover stays open — used in tests / debugging. */
        alwaysOpen?: boolean;
    }>(),
    { alwaysOpen: false },
);

const emit = defineEmits<{ (e: 'select', tagId: string): void }>();

// Controlled open so a selection can close the popover. Without this
// the popover stays open after a click and its dismiss-layer overlay
// swallows the next click on the page.
const open = ref(props.alwaysOpen);

const sorted = computed<TagRow[]>(() =>
    [...props.availableTags].sort((a, b) =>
        a.name.toLowerCase().localeCompare(b.name.toLowerCase()),
    ),
);

function onSelect(tag: TagRow): void {
    emit('select', tag.id);
    if (!props.alwaysOpen) open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                data-testid="tag-picker-popover-trigger"
                class="inline-flex cursor-pointer items-center border-none bg-transparent p-0 leading-none focus:outline-none"
                aria-label="Add tag"
            >
                <PlusCircle class="size-4 text-lime-700 hover:text-lime-500" :stroke-width="3" />
            </button>
        </PopoverTrigger>

        <PopoverContent
            side="bottom"
            align="start"
            :side-offset="6"
            class="max-h-[calc(100vh-8rem)] w-auto max-w-[min(90vw,40rem)] overflow-auto p-1"
        >
            <div v-if="sorted.length === 0" class="px-3 py-2 text-xs italic text-muted-foreground">
                No tags available.
            </div>
            <div v-else class="grid grid-cols-2 gap-x-1 gap-y-0.5">
                <button
                    v-for="tag in sorted"
                    :key="tag.id"
                    type="button"
                    data-testid="tag-picker-popover-suggestion"
                    class="flex items-center rounded px-1 py-0.5 text-left hover:bg-accent focus:outline-none"
                    @mousedown.prevent="onSelect(tag)"
                >
                    <TagPill :tag="tag" size="sm" />
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
