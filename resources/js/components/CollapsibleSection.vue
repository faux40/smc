<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

/**
 * A bordered page section that rolls up, matching the plain `<section>`s it
 * sits among. The whole header is the control — a chevron alone is a small
 * target and says nothing about what it opens.
 *
 * `summary` is what makes leaving it shut viable: the header has to carry
 * enough for someone to decide they don't need to open it.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        /** Shown beside the title, open or shut (e.g. "8h · expires 06/01/27"). */
        summary?: string | null;
        defaultOpen?: boolean;
    }>(),
    { summary: null, defaultOpen: false },
);

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const open = ref(props.defaultOpen);

// The default is decided from data that arrives after mount (a class detail
// is fetched), so a later change has to be able to reach a section the user
// hasn't touched. Once opened or shut by hand, it stays where it was put.
const touched = ref(false);

watch(
    () => props.defaultOpen,
    (v) => {
        if (!touched.value) {
            open.value = v;
        }
    },
);

function toggle(v: boolean): void {
    touched.value = true;
    open.value = v;
    emit('update:open', v);
}
</script>

<template>
    <Collapsible
        :open="open"
        as="section"
        class="rounded-md border border-border"
        @update:open="toggle"
    >
        <CollapsibleTrigger as-child>
            <button
                type="button"
                data-testid="section-toggle"
                :aria-expanded="open"
                :aria-label="`${open ? 'Hide' : 'Show'} ${title}`"
                class="flex w-full items-center gap-3 p-4 text-left hover:bg-muted/40"
            >
                <h2 class="text-sm font-semibold">{{ title }}</h2>
                <span
                    v-if="summary"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ summary }}
                </span>
                <!-- The word, not just the chevron: "hide/show" is the thing
                     being offered, and it reads at a glance. -->
                <span
                    class="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                >
                    {{ open ? 'Hide' : 'Show' }}
                    <ChevronDown
                        class="size-4 transition-transform"
                        :class="open && 'rotate-180'"
                    />
                </span>
            </button>
        </CollapsibleTrigger>

        <CollapsibleContent>
            <div class="border-t border-border p-4">
                <slot />
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
