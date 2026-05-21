<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';

/*
 * Reusable async-region wrapper (Phase 16.7). Renders, in priority order:
 *   loading  → spinner + label
 *   error    → red banner (the page's caught fetch error)
 *   empty    → the #empty slot (or a default "nothing here" message)
 *   else     → the default slot (the loaded content)
 *
 * Pages own the flags: a local `loading` ref toggled around the initial
 * fetch, the page's `error` ref, and an emptiness condition. Keeps the
 * loading/empty/error UX identical across every Pinia-backed list page
 * instead of each one re-implementing it inline.
 */
withDefaults(
    defineProps<{
        loading?: boolean;
        error?: string | null;
        empty?: boolean;
        loadingText?: string;
        emptyText?: string;
    }>(),
    {
        loading: false,
        error: null,
        empty: false,
        loadingText: 'Loading…',
        emptyText: 'Nothing here yet.',
    },
);
</script>

<template>
    <div
        v-if="loading"
        class="flex items-center justify-center gap-2 p-6 text-sm text-muted-foreground"
    >
        <Spinner />
        <span>{{ loadingText }}</span>
    </div>
    <p
        v-else-if="error"
        class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
    >
        {{ error }}
    </p>
    <slot v-else-if="empty" name="empty">
        <div
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            {{ emptyText }}
        </div>
    </slot>
    <slot v-else />
</template>
