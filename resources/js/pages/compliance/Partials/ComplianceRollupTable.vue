<script setup lang="ts">
/*
 * One compliance roll-up table (by training OR by requirement). Identical
 * shape for both dimensions, so the page mounts it twice with a different
 * fetcher + name label. Server-paged via useServerTable; per-status counts
 * come straight off the materialized status.
 */
import { onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerTable } from '@/composables/useServerTable';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import type { ComplianceRow } from '@/stores/compliance';

const props = defineProps<{
    viewId: string;
    nameLabel: string;
    searchPlaceholder: string;
    fetcher: (
        params: ServerTableQuery,
    ) => Promise<ServerTableResponse<ComplianceRow>>;
}>();

const COLUMNS = [
    { key: 'name', label: props.nameLabel, sortable: true },
    { key: 'overdue', label: 'Overdue', sortable: true },
    { key: 'due_soon', label: 'Due soon', sortable: true },
    { key: 'not_started', label: 'Not started', sortable: true },
    { key: 'current', label: 'Current', sortable: true },
    { key: 'as_needed', label: 'As-needed', sortable: true },
    { key: 'total', label: 'Total', sortable: true },
];

const error = ref<string | null>(null);
const initialLoading = ref(true);
const search = ref('');

const table = useServerTable<ComplianceRow>((params) => props.fetcher(params), {
    perPage: 25,
    sort: 'overdue',
    dir: 'desc',
});

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

onMounted(async () => {
    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
    }
});
</script>

<template>
    <AsyncState :loading="initialLoading" :error="error">
        <DataTable
            :view-id="viewId"
            :default-columns="COLUMNS"
            :rows="table.rows.value"
            :sort-key="table.sort.value"
            :sort-dir="table.dir.value"
            :row-key="(row) => row.id"
            @sort="table.setSort"
        >
            <template #filters>
                <div class="grid gap-1">
                    <Label :for="`${viewId}_q`" class="text-xs">Search</Label>
                    <Input
                        :id="`${viewId}_q`"
                        :model-value="search"
                        :placeholder="searchPlaceholder"
                        class="h-8 w-64"
                        @update:model-value="onSearch"
                    />
                </div>
            </template>

            <template #col-name="{ row }">
                <span class="font-medium">{{ row.name }}</span>
            </template>

            <template #col-overdue="{ row }">
                <span
                    v-if="row.counts.overdue > 0"
                    class="font-medium text-red-700 dark:text-red-300"
                >
                    {{ row.counts.overdue }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #col-due_soon="{ row }">
                <span
                    v-if="row.counts.due_soon > 0"
                    class="font-medium text-amber-700 dark:text-amber-300"
                >
                    {{ row.counts.due_soon }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #col-not_started="{ row }">{{
                row.counts.not_started
            }}</template>
            <template #col-current="{ row }">{{ row.counts.current }}</template>
            <template #col-as_needed="{ row }">{{
                row.counts.as_needed
            }}</template>
            <template #col-total="{ row }">
                <span class="font-medium">{{ row.total }}</span>
            </template>

            <template #empty>No compliance rows match the current search.</template>
        </DataTable>

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
