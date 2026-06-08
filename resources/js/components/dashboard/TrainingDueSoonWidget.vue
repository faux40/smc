<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { realtimeTabId } from '@/echo';
import { show as userShow } from '@/routes/users';

interface TrainingDueSoonRow {
    id: string;
    user_id: string;
    user_name: string;
    training_name: string;
    expires_at: string;
}

const rows = ref<TrainingDueSoonRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get<TrainingDueSoonRow[]>(
            '/api/dashboard/training-due-soon',
            { headers: defaultHeaders() },
        );
        rows.value = data;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}
</script>

<template>
    <DashWidget
        widget-id="training-due-soon"
        title="Training expiring soon"
        description="Assignments expiring within the org's due-soon window, earliest first."
        :loading="loading"
        :error="error"
    >
        <div
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No training assignments expiring soon.
        </div>

        <ul v-else class="divide-y divide-border">
            <li
                v-for="row in rows"
                :key="row.id"
                class="flex items-center justify-between gap-3 py-2"
            >
                <div class="min-w-0">
                    <Link
                        :href="userShow(row.user_id).url"
                        class="block truncate font-medium text-primary hover:underline"
                    >
                        {{ row.user_name }}
                    </Link>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ row.training_name }}
                    </p>
                </div>
                <span class="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {{ row.expires_at }}
                </span>
            </li>
        </ul>
    </DashWidget>
</template>
