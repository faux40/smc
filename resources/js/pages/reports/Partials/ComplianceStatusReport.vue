<script setup lang="ts">
/*
 * Compliance-status tab of the Reports page — the audit document. One row per
 * (employee, assigned training) showing the CURRENT status, expiry/due date,
 * days-until-due, and source (requirement or Direct). Never-started people are
 * included (they're assignments with status not_started/overdue). Filterable by
 * status (multi), tag, and search; "Export…" opens the grouping modal whose
 * PDF + CSV links stream the full filtered set (same filters + visible columns
 * + grouping + optional requirement/training scope).
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import Pagination from '@/components/Pagination.vue';
import ReportGroupingModal from '@/components/ReportGroupingModal.vue';
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
import type { ComplianceStatusRow } from '@/stores/reports';
import { useTagsStore } from '@/stores/tags';

const props = withDefaults(
    defineProps<{
        // Optional scope — passed by the RequirementDetail export so the report
        // shows just that requirement's people (or a single training).
        requirementId?: string;
        trainingId?: string;
        viewId?: string;
    }>(),
    { requirementId: undefined, trainingId: undefined, viewId: 'reports-compliance-status' },
);

const COLUMNS = [
    { key: 'user', label: 'User', sortable: false },
    { key: 'employee_number', label: 'Employee #', sortable: false },
    { key: 'department', label: 'Department', sortable: false },
    { key: 'location', label: 'Location', sortable: false },
    { key: 'training', label: 'Training', sortable: false },
    { key: 'status', label: 'Status', sortable: false },
    { key: 'expires_at', label: 'Expires / Due', sortable: false },
    { key: 'days_until_due', label: 'Days until due', sortable: false },
    { key: 'source', label: 'Source', sortable: false },
];

const USER_MORPH = 'App\\Models\\User';

// Status buckets mirror TrainingStatusService::STATUSES (worst-first).
const STATUS_OPTIONS = [
    { id: 'overdue', label: 'Overdue' },
    { id: 'due_soon', label: 'Due soon' },
    { id: 'not_started', label: 'Not started' },
    { id: 'current', label: 'Current' },
    { id: 'as_needed', label: 'As needed' },
];

// Grouping dimensions offered for this report (keys mirror ReportGrouping).
const GROUP_OPTIONS = [
    { key: 'department', label: 'Department' },
    { key: 'location', label: 'Location' },
    { key: 'status', label: 'Status' },
    { key: 'training', label: 'Training' },
    { key: 'source', label: 'Source' },
];

const store = useReportsStore();
const tagsStore = useTagsStore();
const page = usePage();
const authUser = computed(
    () => page.props.auth.user as { org_id?: string } | null,
);

const error = ref<string | null>(null);
const initialLoading = ref(true);

const search = ref('');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');
const statusFilter = ref<string[]>([]);
const groupingOpen = ref(false);

const table = useServerTable<ComplianceStatusRow>(
    (params) =>
        store.fetchComplianceStatus({
            ...params,
            q: search.value,
            statuses: statusFilter.value,
            tags: tagFilter.value,
            tags_mode: tagFilterMode.value,
            requirement_id: props.requirementId,
            training_id: props.trainingId,
        }),
    { perPage: 25, sort: null, dir: 'desc' },
);

const reportView = useTableView(props.viewId, COLUMNS);

function reloadNow(): void {
    table.reload();
}
function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

const exportHref = computed(() => {
    const params = new URLSearchParams();
    if (search.value) params.set('q', search.value);
    for (const s of statusFilter.value) params.append('statuses[]', s);
    for (const id of tagFilter.value) params.append('tags[]', id);
    if (tagFilter.value.length > 0) params.set('tags_mode', tagFilterMode.value);
    if (props.requirementId) params.set('requirement_id', props.requirementId);
    if (props.trainingId) params.set('training_id', props.trainingId);
    for (const col of reportView.visibleColumns.value)
        params.append('columns[]', col.key);
    const qs = params.toString();
    return `/api/reports/compliance-status/export${qs ? `?${qs}` : ''}`;
});

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
    <div class="flex flex-col gap-6">
        <div class="flex items-start justify-between gap-4">
            <p class="max-w-3xl text-sm text-muted-foreground">
                Every employee × required training with its current status and
                due date — including people who have never started. Hand this to
                an inspector. Export the filtered set to PDF or CSV.
            </p>
            <Button
                variant="outline"
                size="sm"
                data-testid="open-compliance-grouping-modal"
                @click="groupingOpen = true"
            >
                Export…
            </Button>
        </div>

        <ReportGroupingModal
            v-model:open="groupingOpen"
            :base-href="exportHref"
            :options="GROUP_OPTIONS"
        />

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                :view-id="props.viewId"
                :default-columns="COLUMNS"
                :rows="table.rows.value"
                :sort-key="null"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
            >
                <template #filters>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="grid gap-1">
                            <Label for="cs_search" class="text-xs">Search</Label>
                            <Input
                                id="cs_search"
                                :model-value="search"
                                placeholder="Name, email, EE#, dept, location…"
                                class="h-8 w-72"
                                @update:model-value="onSearch"
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
                    <ComplianceStatusBadge
                        :status="(row.status_key as ComplianceStatus)"
                    />
                </template>

                <template #col-tags="{ row }">
                    <TagsListCell
                        :morphable-type="USER_MORPH"
                        :morphable-id="row.user_id"
                        readonly
                    />
                </template>

                <template #empty>No assignments match the current filters.</template>
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
