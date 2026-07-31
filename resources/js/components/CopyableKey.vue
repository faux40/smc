<script setup lang="ts">
/**
 * A `${key}` you can actually get out of the screen and into a slide.
 *
 * Three ways, because the first two can fail silently in this app's dev
 * environment (plain HTTP → no `navigator.clipboard`): click to copy, an
 * explicit "Copied"/"Select it and copy" answer so the click is never a
 * guess, and `select-all` text so a double-click selects the whole key for a
 * manual copy even when the browser refuses to do it for us.
 */
import { ref } from 'vue';
import { copyText } from '@/lib/clipboard';

const props = defineProps<{ text: string }>();

type Result = 'idle' | 'copied' | 'failed';

const result = ref<Result>('idle');
let clearTimer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    result.value = (await copyText(props.text)) ? 'copied' : 'failed';

    clearTimeout(clearTimer);
    // Long enough to read, short enough that the row settles back down.
    clearTimer = setTimeout(() => (result.value = 'idle'), 2500);
}
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <button
            type="button"
            data-testid="copy-key"
            class="rounded border border-border px-2 py-1 font-mono text-xs hover:bg-muted"
            :class="result === 'copied' ? 'border-green-500 bg-green-50' : ''"
            :aria-label="`Copy ${text}`"
            :title="`Copy ${text}`"
            @click="copy"
        >
            <!-- select-all: a double-click grabs the whole key, not a word of
                 it, for when the clipboard API isn't available. -->
            <span data-testid="key-text" class="select-all">{{ text }}</span>
        </button>

        <span
            v-if="result === 'copied'"
            class="text-xs text-green-700"
            role="status"
        >
            Copied
        </span>
        <span
            v-else-if="result === 'failed'"
            class="text-xs text-amber-700"
            role="status"
        >
            Select it and copy
        </span>
    </span>
</template>
