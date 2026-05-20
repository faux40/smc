<script setup lang="ts">
/*
 * Five at-a-glance count cards: overdue / due-soon / current / never-
 * started / inactive. Owns its own fetch so widgets stay portable when
 * the future user-prefs phase lets people re-order / add / remove them.
 */
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { realtimeTabId } from '@/echo';

interface SummaryPayload {
    counts: {
        overdue: number;
        due_soon: number;
        current: number;
        never_started: number;
        inactive: number;
    };
    total_assignments: number;
    total_users: number;
    users_with_overdue: number;
}

const data = ref<SummaryPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data: resp } = await axios.get<SummaryPayload>(
            '/api/dashboard/summary',
            { headers: defaultHeaders() },
        );
        data.value = resp;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const CARDS: Array<{
    key: keyof SummaryPayload['counts'];
    label: string;
    classes: string;
}> = [
    {
        key: 'overdue',
        label: 'Overdue',
        classes:
            'bg-red-50 text-red-900 ring-red-200 dark:bg-red-900/30 dark:text-red-100 dark:ring-red-800',
    },
    {
        key: 'due_soon',
        label: 'Due soon',
        classes:
            'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800',
    },
    {
        key: 'current',
        label: 'Current',
        classes:
            'bg-emerald-50 text-emerald-900 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-800',
    },
    {
        key: 'never_started',
        label: 'Not started',
        classes: 'bg-muted text-muted-foreground ring-border',
    },
    {
        key: 'inactive',
        label: 'Inactive',
        classes: 'bg-muted text-muted-foreground ring-border',
    },
];

const footer = computed(() =>
    data.value
        ? `${data.value.total_users} user(s) · ${data.value.total_assignments} active assignment(s) · ${data.value.users_with_overdue} user(s) with overdue items`
        : '',
);

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
        widget-id="summary-stats"
        title="Compliance summary"
        description="Active assignments across the org, grouped by status."
        :loading="loading"
        :error="error"
    >
        <div v-if="data" class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div
                v-for="card in CARDS"
                :key="card.key"
                class="flex flex-col items-start gap-1 rounded-lg p-3 ring-1 ring-inset"
                :class="card.classes"
            >
                <span class="text-xs font-medium tracking-wide uppercase">{{
                    card.label
                }}</span>
                <span class="text-2xl font-semibold tabular-nums">
                    {{ data.counts[card.key] }}
                </span>
            </div>
        </div>

        <template #footer>{{ footer }}</template>
    </DashWidget>
</template>
