<script setup lang="ts">
/*
 * Reports — org completion report. Server-paged table of completions with date
 * range / training / user / tag filters; "Export PDF" streams the full filtered
 * set via the export endpoint (same filters, as a query string).
 */
import { Head, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import Pagination from '@/components/Pagination.vue';
import TagFilter from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerTable } from '@/composables/useServerTable';
import { useTableView } from '@/composables/useTableView';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { useReportsStore } from '@/stores/reports';
import type { CompletionReportRow } from '@/stores/reports';
import { useTagsStore } from '@/stores/tags';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reports', href: '/reports' }],
    },
});

const COLUMNS = [
    { key: 'user', label: 'User', sortable: false },
    { key: 'employee_number', label: 'Employee #', sortable: false },
    { key: 'department', label: 'Department', sortable: false },
    { key: 'location', label: 'Location', sortable: false },
    { key: 'training', label: 'Training', sortable: false },
    { key: 'completion_date', label: 'Completed', sortable: false },
    { key: 'expire_date', label: 'Expires', sortable: false },
    { key: 'status', label: 'Status', sortable: false },
    { key: 'tags', label: 'Tags', sortable: false },
    { key: 'hours', label: 'Hours', sortable: false },
    { key: 'class', label: 'Class', sortable: false },
    { key: 'cert_id', label: 'Cert ID', sortable: false },
];

const USER_MORPH = 'App\\Models\\User';

// Shared between the DataTable and the page-side useTableView below, so the
// export link can read the same resolved column visibility/order the table shows.
const REPORT_VIEW_ID = 'reports-completions';

// Expiry statuses match ExpiryStatus keys/labels (mirrors the Status column).
const STATUS_OPTIONS = [
    { id: 'expired', label: 'Expired' },
    { id: 'due_soon', label: 'Expires soon' },
    { id: 'current', label: 'Current' },
];

const store = useReportsStore();
const tagsStore = useTagsStore();
const page = usePage();
const authUser = computed(
    () => page.props.auth.user as { org_id?: string } | null,
);

const error = ref<string | null>(null);
const initialLoading = ref(true);

const from = ref('');
const to = ref('');
const trainingSearch = ref('');
const userSearch = ref('');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');
const statusFilter = ref<string[]>([]);

const table = useServerTable<CompletionReportRow>(
    (params) =>
        store.fetchCompletions({
            ...params,
            q: trainingSearch.value,
            from: from.value || undefined,
            to: to.value || undefined,
            user_q: userSearch.value,
            tags: tagFilter.value,
            tags_mode: tagFilterMode.value,
            statuses: statusFilter.value,
        }),
    { perPage: 25, sort: null, dir: 'desc' },
);

// Mirror the table's resolved column state (same viewId + COLUMNS) so the
// export link can carry the visible columns, in order, to the PDF.
const reportView = useTableView(REPORT_VIEW_ID, COLUMNS);

const debouncedReload = useDebounceFn(() => table.reload(), 300);
function reloadNow(): void {
    table.reload();
}

// The export link carries the current filters as a query string.
const exportHref = computed(() => {
    const params = new URLSearchParams();
    if (trainingSearch.value) params.set('q', trainingSearch.value);
    if (from.value) params.set('from', from.value);
    if (to.value) params.set('to', to.value);
    if (userSearch.value) params.set('user_q', userSearch.value);
    for (const id of tagFilter.value) params.append('tags[]', id);
    if (tagFilter.value.length > 0) params.set('tags_mode', tagFilterMode.value);
    for (const s of statusFilter.value) params.append('statuses[]', s);
    for (const col of reportView.visibleColumns.value)
        params.append('columns[]', col.key);
    const qs = params.toString();
    return `/api/reports/completions/export${qs ? `?${qs}` : ''}`;
});

// Hydrate the tags store for each fetched page so the Tags column renders
// attached pills without a per-row fetch (same pattern as users/Index).
watch(
    () => table.rows.value,
    (rows) => {
        for (const row of rows) {
            tagsStore.setAttached(
                { type: USER_MORPH, id: row.user_id },
                row.tag_ids ?? [],
            );
        }
    },
);

onMounted(async () => {
    tagsStore.loadLibrary().catch(() => {
        /* surfaced through the store */
    });
    if (authUser.value?.org_id) {
        tagsStore.subscribe(authUser.value.org_id);
    }

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
    <Head title="Reports" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Reports"
                description="Completion report — every recorded completion, filterable by date, training, user, and tag. Export the filtered set to PDF."
            />
            <Button as-child variant="outline" size="sm">
                <a
                    :href="exportHref"
                    target="_blank"
                    rel="noopener"
                    data-testid="export-completion-report"
                >
                    Export PDF
                </a>
            </Button>
        </div>

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                :view-id="REPORT_VIEW_ID"
                :default-columns="COLUMNS"
                :rows="table.rows.value"
                :sort-key="null"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
            >
                <template #filters>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="grid gap-1">
                            <Label for="rep_from" class="text-xs">From</Label>
                            <Input
                                id="rep_from"
                                type="date"
                                :model-value="from"
                                class="h-8 w-40"
                                @update:model-value="(v) => { from = String(v); reloadNow(); }"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="rep_to" class="text-xs">To</Label>
                            <Input
                                id="rep_to"
                                type="date"
                                :model-value="to"
                                class="h-8 w-40"
                                @update:model-value="(v) => { to = String(v); reloadNow(); }"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="rep_training" class="text-xs">Training</Label>
                            <Input
                                id="rep_training"
                                :model-value="trainingSearch"
                                placeholder="Training name…"
                                class="h-8 w-48"
                                @update:model-value="(v) => { trainingSearch = String(v); debouncedReload(); }"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="rep_user" class="text-xs">User</Label>
                            <Input
                                id="rep_user"
                                :model-value="userSearch"
                                placeholder="Name, EE#, dept…"
                                class="h-8 w-48"
                                @update:model-value="(v) => { userSearch = String(v); debouncedReload(); }"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Status</Label>
                            <MultiSelectFilter
                                :options="STATUS_OPTIONS"
                                :selected="statusFilter"
                                mode="or"
                                :show-mode="false"
                                :searchable="false"
                                label="statuses"
                                @update:selected="(v) => { statusFilter = v; reloadNow(); }"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Tags</Label>
                            <TagFilter
                                v-model:tag-ids="tagFilter"
                                v-model:mode="tagFilterMode"
                                placeholder="Any tag…"
                                @update:tag-ids="reloadNow"
                                @update:mode="reloadNow"
                            />
                        </div>
                    </div>
                </template>

                <template #col-status="{ row }">
                    <ComplianceStatusBadge :status="(row._band as ComplianceStatus)" />
                </template>

                <template #col-tags="{ row }">
                    <TagsListCell
                        :morphable-type="USER_MORPH"
                        :morphable-id="row.user_id"
                        readonly
                    />
                </template>

                <template #empty>No completions match the current filters.</template>
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
    </div>
</template>
