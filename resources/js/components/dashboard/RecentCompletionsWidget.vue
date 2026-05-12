<script setup lang="ts">
/*
 * Activity-feed-ish: last 10 completions org-wide, newest first.
 * Doubles as a low-key paper trail.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { Badge } from '@/components/ui/badge';
import { realtimeTabId } from '@/echo';
import { show as userShow } from '@/routes/users';

interface RecentCompletionRow {
    id: string;
    user_id: string;
    user_name: string | null;
    module_label: string;
    completion_date: string | null;
    expire_date: string | null;
    credits_count: number;
}

const rows = ref<RecentCompletionRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get<RecentCompletionRow[]>(
            '/api/dashboard/recent-completions',
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
        widget-id="recent-completions"
        title="Recent completions"
        description="The last 10 completions on file."
        :loading="loading"
        :error="error"
    >
        <div
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No completions on file yet.
        </div>

        <ul v-else class="divide-y divide-border">
            <li v-for="row in rows" :key="row.id" class="flex items-center justify-between gap-3 py-2">
                <div class="min-w-0">
                    <Link
                        :href="userShow(row.user_id)"
                        class="block truncate font-medium text-primary hover:underline"
                    >
                        {{ row.user_name ?? row.user_id }}
                    </Link>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ row.module_label }}
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <Badge variant="secondary">{{ row.credits_count }}</Badge>
                    <span class="text-muted-foreground tabular-nums">
                        {{ row.completion_date ?? '—' }}
                    </span>
                </div>
            </li>
        </ul>
    </DashWidget>
</template>
