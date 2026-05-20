<script setup lang="ts">
/*
 * Compact view+add+remove widget for list rows. Renders the attached
 * tags as small TagPills + a tiny "+" PlusCircle that opens a popover
 * with the unattached tags. Hovering a pill reveals an X-button that
 * detaches without confirmation (low cost — re-attach is one click).
 *
 * Talks to `useTagsStore`:
 *   - reads `attached[morphableKey]` so realtime broadcasts patch the
 *     cell automatically across tabs;
 *   - calls `attach()` / `detach()` for the originating mutation.
 *
 * The host page is responsible for one of these on first load:
 *   - calling `store.setAttached(morphable, initialIds)` with the IDs
 *     baked into the row payload (preferred — no extra request); OR
 *   - relying on a separate fetch elsewhere that hydrates the store.
 *
 * Pattern adapted from another app's TagPicker / TagPickerPopover.
 */

import { X } from 'lucide-vue-next';
import { computed } from 'vue';
import TagPickerPopover from '@/components/TagPickerPopover.vue';
import TagPill from '@/components/TagPill.vue';
import { useTagsStore } from '@/stores/tags';
import type { TagRow } from '@/stores/tags';

const props = defineProps<{
    morphableType: string;
    morphableId: string;
}>();

const store = useTagsStore();

const morphable = computed(() => ({
    type: props.morphableType,
    id: props.morphableId,
}));

const attached = computed<TagRow[]>(() =>
    store.attachedTagsFor(morphable.value),
);

const available = computed<TagRow[]>(() => {
    const ids = new Set(attached.value.map((t) => t.id));

    return store.library.filter((t) => !ids.has(t.id));
});

async function onAttach(tagId: string): Promise<void> {
    await store.attach(morphable.value, tagId);
}

async function onDetach(tagId: string): Promise<void> {
    await store.detach(morphable.value, tagId);
}
</script>

<template>
    <div class="inline-flex flex-wrap items-center gap-1.5 align-middle">
        <span
            v-for="tag in attached"
            :key="tag.id"
            class="group/tag relative inline-flex items-center"
        >
            <TagPill :tag="tag" size="sm" />
            <button
                type="button"
                :aria-label="`Remove ${tag.name}`"
                :title="`Remove ${tag.name}`"
                class="absolute -top-1.5 -right-1.5 hidden size-4 cursor-pointer items-center justify-center rounded-full bg-background text-muted-foreground ring-1 ring-border group-hover/tag:inline-flex hover:bg-destructive hover:text-destructive-foreground"
                @click.stop="onDetach(tag.id)"
            >
                <X class="size-3" :stroke-width="3" />
            </button>
        </span>

        <TagPickerPopover :available-tags="available" @select="onAttach" />
    </div>
</template>
