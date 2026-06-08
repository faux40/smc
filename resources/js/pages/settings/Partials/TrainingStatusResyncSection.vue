<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { realtimeTabId } from '@/echo';

type Result = { processed: number };

const running = ref(false);
const result = ref<Result | null>(null);
const error = ref<string | null>(null);

async function run(): Promise<void> {
    running.value = true;
    result.value = null;
    error.value = null;

    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const { data } = await axios.post<Result>(
            '/api/settings/training-status-resync',
            {},
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Origin-Tab': realtimeTabId(),
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
            },
        );
        result.value = data;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <Heading
            variant="small"
            title="Data maintenance"
            description="Re-sync training assignment statuses from completion history. Use this if expiry dates appear out of date."
        />

        <div class="flex items-center gap-4">
            <Button
                variant="outline"
                :disabled="running"
                data-testid="resync-btn"
                @click="run"
            >
                {{ running ? 'Running…' : 'Re-sync training statuses' }}
            </Button>

            <p
                v-if="result !== null"
                class="text-sm text-green-700 dark:text-green-400"
                data-testid="resync-success"
            >
                Done — {{ result.processed }} assignment(s) recalculated.
            </p>

            <p
                v-if="error"
                class="text-sm text-red-700 dark:text-red-400"
                data-testid="resync-error"
            >
                {{ error }}
            </p>
        </div>
    </div>
</template>
