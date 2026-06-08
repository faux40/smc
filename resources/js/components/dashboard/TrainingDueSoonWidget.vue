<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { realtimeTabId } from '@/echo';
import { show as userShow } from '@/routes/users';

interface TrainingDueSoonRow {
    id: string;
    user_id: string;
    user_name: string;
    training_name: string;
    expires_at: string | null;
}

const overdue = ref<TrainingDueSoonRow[]>([]);
const dueSoon = ref<TrainingDueSoonRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const isEmpty = computed(() => overdue.value.length === 0 && dueSoon.value.length === 0);

onMounted(async () => {
    try {
        const { data } = await axios.get<{ overdue: TrainingDueSoonRow[]; due_soon: TrainingDueSoonRow[] }>(
            '/api/dashboard/training-due-soon',
            { headers: defaultHeaders() },
        );
        overdue.value = Array.isArray(data?.overdue) ? data.overdue : [];
        dueSoon.value = Array.isArray(data?.due_soon) ? data.due_soon : [];
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
        title="Training status"
        description="Overdue, never-started, and expiring assignments."
        :loading="loading"
        :error="error"
    >
        <div
            v-if="isEmpty"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No overdue or expiring training assignments.
        </div>

        <div v-else class="space-y-4">
            <!-- Overdue / Never started -->
            <div v-if="overdue.length > 0">
                <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">
                    Overdue / Not started
                </h4>
                <ul class="divide-y divide-border">
                    <li
                        v-for="row in overdue"
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
                        <span class="shrink-0 text-xs tabular-nums text-red-500 dark:text-red-400">
                            {{ row.expires_at ?? 'Never completed' }}
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Expiring soon -->
            <div v-if="dueSoon.length > 0">
                <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
                    Expiring soon
                </h4>
                <ul class="divide-y divide-border">
                    <li
                        v-for="row in dueSoon"
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
            </div>
        </div>
    </DashWidget>
</template>
