<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onErrorCaptured, onMounted, onUnmounted, ref } from 'vue';

/*
 * Page-load error boundary (Phase 16.7).
 *
 * An unhandled error in a page component's setup/render otherwise tears down
 * the whole Vue tree, leaving a blank screen. We catch it here, show a
 * recoverable fallback, and clear it on the next successful Inertia
 * navigation — so a single bad page doesn't brick the app.
 */
const error = ref<Error | null>(null);

onErrorCaptured((err) => {
    error.value = err instanceof Error ? err : new Error(String(err));

    // Stop propagation: we've rendered a fallback in its place.
    return false;
});

// A completed navigation means a fresh page subtree — clear any stale error.
let stopNavigate: (() => void) | undefined;

onMounted(() => {
    stopNavigate = router.on('navigate', () => {
        error.value = null;
    });
});

onUnmounted(() => {
    stopNavigate?.();
});

function reload(): void {
    error.value = null;
    router.reload();
}
</script>

<template>
    <div
        v-if="error"
        class="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center"
    >
        <h1 class="text-lg font-semibold text-foreground">
            Something went wrong
        </h1>
        <p class="max-w-md text-sm text-muted-foreground">
            This page hit an unexpected error and couldn't be displayed. You can
            reload to try again — your data is safe.
        </p>
        <button
            type="button"
            class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"
            @click="reload"
        >
            Reload page
        </button>
    </div>
    <slot v-else />
</template>
