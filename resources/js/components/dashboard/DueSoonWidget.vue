<script setup lang="ts">
/*
 * Action queue: (user, requirement) pairs due within the 60-day window,
 * earliest first. Up to 50 rows per fetch — the widget can grow
 * pagination later if real orgs hit the cap.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { realtimeTabId } from '@/echo';
import { show as userShow } from '@/routes/users';

interface DueSoonRow {
    assignment_id: string;
    user_id: string;
    user_name: string | null;
    requirement_name: string;
    next_due_date: string | null;
    days_until_due: number | null;
}

const rows = ref<DueSoonRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get<DueSoonRow[]>(
            '/api/dashboard/due-soon',
            { headers: defaultHeaders() },
        );
        rows.value = data;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const dueLabel = (row: DueSoonRow): string => {
    if (row.days_until_due === null) return row.next_due_date ?? '—';
    if (row.days_until_due === 0) return `${row.next_due_date} (today)`;
    return `${row.next_due_date} (in ${row.days_until_due}d)`;
};

function defaultHeaders(): Record<string, string> {
    const csrf = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
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
        widget-id="due-soon"
        title="Due soon"
        description="Active assignments coming due in the next 60 days."
        :loading="loading"
        :error="error"
    >
        <div
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            Nothing due in the next 60 days.
        </div>

        <ul v-else class="divide-y divide-border">
            <li
                v-for="row in rows"
                :key="`${row.user_id}|${row.assignment_id}`"
                class="flex items-center justify-between gap-3 py-2"
            >
                <div class="min-w-0">
                    <Link
                        :href="userShow(row.user_id)"
                        class="block truncate font-medium text-primary hover:underline"
                    >
                        {{ row.user_name ?? row.user_id }}
                    </Link>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ row.requirement_name }}
                    </p>
                </div>
                <span class="text-xs tabular-nums text-muted-foreground">
                    {{ dueLabel(row) }}
                </span>
            </li>
        </ul>
    </DashWidget>
</template>
