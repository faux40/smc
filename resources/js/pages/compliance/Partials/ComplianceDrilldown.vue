<script setup lang="ts">
/*
 * Drill-down list for one compliance row: the users under a training or
 * requirement, worst-status first. Server-paged (small pages) since it lives
 * in an inline panel below the roll-up table.
 */
import { onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import Pagination from '@/components/Pagination.vue';
import { Link } from '@inertiajs/vue3';
import { useServerTable } from '@/composables/useServerTable';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { show as userShow } from '@/routes/users';
import type { ComplianceUserRow } from '@/stores/compliance';

const props = defineProps<{
    fetcher: (
        params: ServerTableQuery,
    ) => Promise<ServerTableResponse<ComplianceUserRow>>;
}>();

const error = ref<string | null>(null);
const loading = ref(true);

const table = useServerTable<ComplianceUserRow>(
    (params) => props.fetcher(params),
    { perPage: 10, sort: null, dir: 'desc' },
);

onMounted(async () => {
    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <AsyncState :loading="loading" :error="error">
        <div
            v-if="table.total.value === 0"
            class="px-3 py-2 text-xs text-muted-foreground"
        >
            No users.
        </div>
        <table v-else class="min-w-full divide-y divide-border text-sm">
            <thead class="bg-muted/40">
                <tr>
                    <th class="px-3 py-2 text-left font-medium">User</th>
                    <th class="px-3 py-2 text-left font-medium">Status</th>
                    <th class="px-3 py-2 text-left font-medium">Expires</th>
                    <th class="px-3 py-2 text-left font-medium">
                        Last completed
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="row in table.rows.value"
                    :key="row.user_id"
                    class="hover:bg-muted/20"
                >
                    <td class="px-3 py-2">
                        <Link
                            :href="userShow(row.user_id)"
                            class="font-medium text-primary hover:underline"
                        >
                            {{ row.name ?? row.user_id }}
                        </Link>
                    </td>
                    <td class="px-3 py-2">
                        <ComplianceStatusBadge
                            :status="(row.status as ComplianceStatus)"
                        />
                    </td>
                    <td class="px-3 py-2">{{ row.expires_at ?? '—' }}</td>
                    <td class="px-3 py-2">
                        {{ row.last_completed_at ?? 'never' }}
                    </td>
                </tr>
            </tbody>
        </table>

        <Pagination
            :page="table.page.value"
            :last-page="table.lastPage.value"
            :total="table.total.value"
            :per-page="table.perPage.value"
            :loading="table.loading.value"
            @update:page="table.setPage"
            @update:per-page="table.setPerPage"
        />
    </AsyncState>
</template>
