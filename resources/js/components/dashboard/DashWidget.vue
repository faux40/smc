<script setup lang="ts">
/*
 * Shared dashboard-widget shell.
 *
 * Phase 14 widgets compose this so each piece of UI has a consistent
 * frame (header + body + optional footer) without each widget
 * re-inventing border, padding, loading, and error states.
 *
 * A later phase that ships user-configurable dashboards (add / remove
 * / re-order widgets) will read `widget-id` from each instance and pin
 * its preferences against that stable string.
 */
defineProps<{
    title: string;
    description?: string;
    loading?: boolean;
    error?: string | null;
    // Stable id consumed by the (future) user-prefs layer. Today it's
    // descriptive only - nothing reads it yet.
    widgetId: string;
}>();
</script>

<template>
    <section
        :data-widget-id="widgetId"
        class="flex flex-col rounded-xl border border-border bg-card text-card-foreground shadow-sm"
    >
        <header
            class="flex items-baseline justify-between gap-2 border-b border-border px-4 py-3"
        >
            <div>
                <h3 class="text-sm font-semibold">{{ title }}</h3>
                <p v-if="description" class="text-xs text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <slot name="actions" />
        </header>

        <div class="flex-1 px-4 py-3">
            <p v-if="loading" class="text-sm text-muted-foreground">Loading…</p>
            <p
                v-else-if="error"
                class="rounded bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ error }}
            </p>
            <slot v-else />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t border-border px-4 py-2 text-xs text-muted-foreground"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>
