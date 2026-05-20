<script setup lang="ts">
/*
 * Reusable tag pill — color swatch + name, optional usage-count badge,
 * optional trailing slot (e.g. a remove × button in TagsField).
 *
 * Owned by useTagsStore consumers. The pill takes a TagRow and renders
 * it; it does no I/O. Used by:
 *   - TagsField (per-morphable attached pills)
 *   - pages/tags/Index.vue (library admin, with count badge)
 *
 * Color rendering: 1f / 40 alpha suffixes give a tinted bg + ring while
 * keeping the name legible against light or dark surfaces. Default
 * neutral when tag.color is null.
 */
import { computed } from 'vue';
import type { TagRow } from '@/stores/tags';

type Size = 'sm' | 'md';

const props = withDefaults(
    defineProps<{
        tag: TagRow;
        size?: Size;
        // Optional usage-count badge. Pass null to omit.
        count?: number | null;
    }>(),
    {
        size: 'sm',
        count: null,
    },
);

const baseClasses =
    'inline-flex items-center gap-1 rounded-full font-medium ring-1 ring-inset';
const sizeClasses = computed(() =>
    props.size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs',
);

const swatchColor = computed(() => props.tag.color ?? '#6b7280');
// Explicit font color overrides the derived behavior; null falls back
// to the swatch color (the pre-feature look).
const textColor = computed(() => props.tag.font_color ?? swatchColor.value);

const pillStyle = computed<Record<string, string>>(() => ({
    backgroundColor: `${swatchColor.value}1f`,
    color: textColor.value,
    boxShadow: `inset 0 0 0 1px ${swatchColor.value}40`,
}));

const countBadgeStyle = computed<Record<string, string>>(() => ({
    backgroundColor: `${swatchColor.value}33`,
}));
</script>

<template>
    <span :class="[baseClasses, sizeClasses]" :style="pillStyle">
        <slot name="leading" />
        <span>{{ tag.name }}</span>
        <span
            v-if="count !== null && count !== undefined"
            class="ml-1 rounded-full px-1.5 py-0 text-[10px] leading-tight tabular-nums"
            :style="countBadgeStyle"
            :aria-label="`${count} attached`"
        >
            {{ count }}
        </span>
        <slot />
    </span>
</template>
