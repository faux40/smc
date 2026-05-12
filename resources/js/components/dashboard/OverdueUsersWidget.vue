<script setup lang="ts">
/*
 * Top N users by overdue assignment count, descending. Click through
 * jumps to the user detail page where the manager can act on it.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import DashWidget from '@/components/dashboard/DashWidget.vue';
import { Badge } from '@/components/ui/badge';
import { realtimeTabId } from '@/echo';
import { show as userShow } from '@/routes/users';

interface OverdueRow {
    user_id: string;
    name: string | null;
    email: string | null;
    overdue_count: number;
}

const rows = ref<OverdueRow[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get<OverdueRow[]>(
            '/api/dashboard/overdue-users',
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
        widget-id="overdue-users"
        title="Users with overdue items"
        description="Ranked by how many active assignments are past due."
        :loading="loading"
        :error="error"
    >
        <div
            v-if="rows.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            Nobody is overdue. Quiet day.
        </div>

        <ul v-else class="divide-y divide-border">
            <li v-for="row in rows" :key="row.user_id" class="flex items-center justify-between gap-3 py-2">
                <div class="min-w-0">
                    <Link
                        :href="userShow(row.user_id)"
                        class="block truncate font-medium text-primary hover:underline"
                    >
                        {{ row.name ?? row.email ?? row.user_id }}
                    </Link>
                    <p v-if="row.email" class="truncate text-xs text-muted-foreground">
                        {{ row.email }}
                    </p>
                </div>
                <Badge variant="destructive">
                    {{ row.overdue_count }} overdue
                </Badge>
            </li>
        </ul>
    </DashWidget>
</template>
