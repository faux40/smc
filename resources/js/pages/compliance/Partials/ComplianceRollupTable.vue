<script setup lang="ts">
/*
 * One compliance roll-up table (by training OR by requirement). Identical
 * shape for both dimensions, so the page mounts it twice with a different
 * fetcher + name label. Server-paged via useServerTable; per-status counts
 * come straight off the materialized status.
 */
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerTable } from '@/composables/useServerTable';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import ComplianceDrilldown from '@/pages/compliance/Partials/ComplianceDrilldown.vue';
import type { ComplianceRow, ComplianceUserRow } from '@/stores/compliance';

const props = defineProps<{
    viewId: string;
    nameLabel: string;
    searchPlaceholder: string;
    fetcher: (
        params: ServerTableQuery,
    ) => Promise<ServerTableResponse<ComplianceRow>>;
    // Optional: tabs without a sensible per-row drill-down (e.g. not-required,
    // which mixes assignments + orphan completions) omit it.
    drilldown?: (
        rowId: string,
    ) => (
        params: ServerTableQuery,
    ) => Promise<ServerTableResponse<ComplianceUserRow>>;
    // Optional: make the row name link somewhere (e.g. the training detail).
    rowHref?: (rowId: string) => string;
    // Optional: the count columns to show (default = the 5 compliance buckets;
    // the not-required tab passes Current / Taken-but-Expired).
    countColumns?: Array<{ key: string; label: string }>;
    initialSort?: string;
}>();

const DEFAULT_COUNT_COLUMNS = [
    { key: 'overdue', label: 'Overdue' },
    { key: 'due_soon', label: 'Due soon' },
    { key: 'not_started', label: 'Not started' },
    { key: 'current', label: 'Current' },
    { key: 'as_needed', label: 'As-needed' },
];

// Inline drill-down: the row whose users are shown below the table.
const expanded = ref<{ id: string; name: string } | null>(null);
function toggleExpand(row: ComplianceRow): void {
    expanded.value =
        expanded.value?.id === row.id ? null : { id: row.id, name: row.name };
}

const COLUMNS = computed(() => [
    { key: 'name', label: props.nameLabel, sortable: true },
    ...(props.countColumns ?? DEFAULT_COUNT_COLUMNS).map((c) => ({
        ...c,
        sortable: true,
    })),
    { key: 'total', label: 'Total', sortable: true },
]);

const error = ref<string | null>(null);
const initialLoading = ref(true);
const search = ref('');

const table = useServerTable<ComplianceRow>((params) => props.fetcher(params), {
    perPage: 25,
    sort: props.initialSort ?? 'overdue',
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
                <Link
                    v-if="rowHref"
                    :href="rowHref(row.id)"
                    class="font-medium text-primary hover:underline"
                >
                    {{ row.name }}
                </Link>
                <span v-else class="font-medium">{{ row.name }}</span>
            </template>

            <template #col-overdue="{ row }">
                <span
                    v-if="(row.counts.overdue ?? 0) > 0"
                    class="font-medium text-red-700 dark:text-red-300"
                >
                    {{ row.counts.overdue }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #col-due_soon="{ row }">
                <span
                    v-if="(row.counts.due_soon ?? 0) > 0"
                    class="font-medium text-amber-700 dark:text-amber-300"
                >
                    {{ row.counts.due_soon }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #col-expired="{ row }">
                <span
                    v-if="(row.counts.expired ?? 0) > 0"
                    class="font-medium text-red-700 dark:text-red-300"
                >
                    {{ row.counts.expired }}
                </span>
                <span v-else class="text-muted-foreground">0</span>
            </template>

            <template #col-not_started="{ row }">{{
                row.counts.not_started ?? 0
            }}</template>
            <template #col-current="{ row }">{{ row.counts.current ?? 0 }}</template>
            <template #col-as_needed="{ row }">{{
                row.counts.as_needed ?? 0
            }}</template>
            <template #col-total="{ row }">
                <span class="font-medium">{{ row.total }}</span>
            </template>

            <template #trail-header>
                <th v-if="drilldown" class="px-4 py-2 text-right font-medium">
                    Details
                </th>
            </template>
            <template #trail-cells="{ row }">
                <td v-if="drilldown" class="px-4 py-2 text-right">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :data-testid="`drilldown-${row.id}`"
                        :aria-expanded="expanded?.id === row.id"
                        @click="toggleExpand(row)"
                    >
                        {{ expanded?.id === row.id ? 'Hide' : 'View users' }}
                    </Button>
                </td>
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

        <!-- Drill-down panel for the expanded row. -->
        <div
            v-if="expanded && drilldown"
            class="rounded-md border border-border"
            data-testid="drilldown-panel"
        >
            <div
                class="flex items-center justify-between border-b border-border bg-muted/40 px-3 py-2"
            >
                <span class="text-sm font-medium">
                    {{ nameLabel }}: {{ expanded.name }} — users
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="expanded = null"
                >
                    Close
                </Button>
            </div>
            <ComplianceDrilldown
                :key="expanded.id"
                :fetcher="drilldown(expanded.id)"
            />
        </div>
    </AsyncState>
</template>
