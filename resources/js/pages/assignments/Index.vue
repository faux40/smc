<script setup lang="ts">
/*
 * Training assignments index — one row per user, with that user's
 * (user, training) assignment pills inline. Server-paged: search / user-filter
 * / requirement-filter / tag-filter / sort all run in the DB (the page used to
 * load every assignment + user and aggregate client-side). Select users to
 * bulk-assign a training or requirement.
 */
import { Head, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import type { FilterMode } from '@/components/FilterModeToggle.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import RequirementAssignmentChip from '@/components/RequirementAssignmentChip.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import TrainingAssignmentPill from '@/components/TrainingAssignmentPill.vue';
import TrainingAssignmentPillLegend from '@/components/TrainingAssignmentPillLegend.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerTable } from '@/composables/useServerTable';
import BulkTrainingAssignModal from '@/pages/assignments/Partials/BulkTrainingAssignModal.vue';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';
import { page as assignmentsPage } from '@/routes/assignments';
import { useOrgSettingsStore } from '@/stores/orgSettings';
import { useRequirementAssignmentsStore } from '@/stores/requirementAssignments';
import { useRequirementsStore } from '@/stores/requirements';
import { useTagsStore } from '@/stores/tags';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type {
    AssignmentUserRow,
    TrainingAssignmentRow,
} from '@/stores/trainingAssignments';

const USER_TYPE = 'App\\Models\\User';
const REQUIREMENT_CLASS = 'App\\Models\\Requirement';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Assignments', href: assignmentsPage() }],
    },
});

const ASSIGNMENTS_COLUMNS = [
    { key: 'user', label: 'User', sortable: true },
    { key: 'employee_number', label: 'Employee #', sortable: true },
    { key: 'job_title', label: 'Job title', sortable: true },
    { key: 'department', label: 'Department', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'supervisor', label: 'Supervisor', sortable: true },
    { key: 'tags', label: 'Tags', sortable: false },
    { key: 'assignments', label: 'Net Assignments', sortable: true },
];

// Column key ⇄ server sort key (identity except user → name).
const COLUMN_SORT: Record<string, string> = {
    user: 'name',
    employee_number: 'employee_number',
    job_title: 'job_title',
    department: 'department',
    location: 'location',
    supervisor: 'supervisor',
    assignments: 'assignments',
};
const SORT_COLUMN: Record<string, string> = {
    name: 'user',
    employee_number: 'employee_number',
    job_title: 'job_title',
    department: 'department',
    location: 'location',
    supervisor: 'supervisor',
    assignments: 'assignments',
};

const taStore = useTrainingAssignmentsStore();
const reqAssignStore = useRequirementAssignmentsStore();
const requirements = useRequirementsStore();
const tagsStore = useTagsStore();
const orgSettings = useOrgSettingsStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            id?: string;
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const canCreate = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);

// Filters (all server-side now).
const search = ref(''); // generic search — training names only
const userSearch = ref(''); // user text filter (replaces the user dropdown)
const requirementFilterIds = ref<string[]>([]);
const requirementFilterMode = ref<FilterMode>('or');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');

const error = ref<string | null>(null);
const initialLoading = ref(true);

const table = useServerTable<AssignmentUserRow>(
    (params) =>
        taStore.fetchByUser({
            ...params,
            q: search.value,
            user_q: userSearch.value,
            requirements: requirementFilterIds.value,
            req_mode: requirementFilterMode.value,
            tags: tagFilter.value,
            tags_mode: tagFilterMode.value,
        }),
    { perPage: 25, sort: 'name', dir: 'asc' },
);

const activeColumnKey = computed(() =>
    table.sort.value ? (SORT_COLUMN[table.sort.value] ?? null) : null,
);
function onSort(columnKey: string): void {
    const serverKey = COLUMN_SORT[columnKey];
    if (serverKey) {
        table.setSort(serverKey);
    }
}

const debouncedReload = useDebounceFn(() => table.reload(), 300);
function onSearch(value: string | number): void {
    search.value = String(value);
    debouncedReload();
}
function onUserSearch(value: string | number): void {
    userSearch.value = String(value);
    debouncedReload();
}
function reloadNow(): void {
    table.reload();
}

const hasActiveFilters = computed(
    () =>
        search.value.trim() !== '' ||
        userSearch.value.trim() !== '' ||
        requirementFilterIds.value.length > 0 ||
        tagFilter.value.length > 0,
);
function clearFilters(): void {
    search.value = '';
    userSearch.value = '';
    requirementFilterIds.value = [];
    requirementFilterMode.value = 'or';
    tagFilter.value = [];
    tagFilterMode.value = 'and';
    table.reload();
}

// Hydrate the tags store from each page so TagsListCell paints without a fetch.
watch(
    () => table.rows.value,
    (rows) => {
        for (const r of rows) {
            tagsStore.setAttached({ type: USER_TYPE, id: r.user_id }, r.tag_ids ?? []);
        }
    },
);

// Realtime: a peer/local assignment change re-pulls the current page.
watch(
    () => taStore.revision,
    () => table.refetchSoon(),
);

// Requirement chips for a row — derived from that user's assignment sources
// (was reqAssignStore.forUser over the global cache; now per-row).
function requirementChipsFor(row: AssignmentUserRow) {
    const seen = new Set<string>();
    const out: Array<{ requirement_id: string; requirement_name: string; user_id: string }> = [];
    for (const ta of row.assignments) {
        for (const s of ta.active_sources) {
            if (
                s.sourceable_type === REQUIREMENT_CLASS &&
                s.sourceable_id !== null &&
                !seen.has(s.sourceable_id)
            ) {
                seen.add(s.sourceable_id);
                const req = requirements.library.find((r) => r.id === s.sourceable_id);
                out.push({
                    requirement_id: s.sourceable_id,
                    requirement_name: req?.name ?? 'Unknown Requirement',
                    user_id: row.user_id,
                });
            }
        }
    }
    return out;
}
async function removeRequirement(userId: string, requirementId: string): Promise<void> {
    await reqAssignStore.destroyByRequirement(userId, requirementId);
    table.refetchSoon();
}

const requirementOptions = computed(() =>
    [...requirements.library]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((r) => ({ id: r.id, label: r.name })),
);

// Row selection for bulk assign — persists across pages.
const selectedUserIds = ref<Set<string>>(new Set());
const isSelected = (userId: string) => selectedUserIds.value.has(userId);
const selectedCount = computed(() => selectedUserIds.value.size);
function toggleUser(userId: string): void {
    const next = new Set(selectedUserIds.value);
    next.has(userId) ? next.delete(userId) : next.add(userId);
    selectedUserIds.value = next;
}
const allOnPage = computed(
    () =>
        table.rows.value.length > 0 &&
        table.rows.value.every((r) => selectedUserIds.value.has(r.user_id)),
);
function toggleAll(): void {
    const next = new Set(selectedUserIds.value);
    if (allOnPage.value) {
        table.rows.value.forEach((r) => next.delete(r.user_id));
    } else {
        table.rows.value.forEach((r) => next.add(r.user_id));
    }
    selectedUserIds.value = next;
}

const bulkOpen = ref(false);
function onBulkApplied(): void {
    selectedUserIds.value = new Set();
    table.refetchSoon();
}

const modalOpen = ref(false);
const modalMode = ref<'create' | 'view'>('create');
const editing = ref<TrainingAssignmentRow | null>(null);
const createUserId = ref<string | null>(null);

const openCreate = (userId: string | null = null) => {
    modalMode.value = 'create';
    editing.value = null;
    createUserId.value = userId;
    modalOpen.value = true;
};
const openView = (row: TrainingAssignmentRow) => {
    modalMode.value = 'view';
    editing.value = row;
    createUserId.value = null;
    modalOpen.value = true;
};

onMounted(async () => {
    if (authUser.value?.org_id) {
        taStore.subscribe(authUser.value.org_id);
        tagsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([
            table.fetchPage(),
            requirements.load(),
            tagsStore.loadLibrary(),
        ]);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
    }
});
</script>

<template>
    <Head title="Assignments" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Assignments"
                description="Per-(user, training) compliance records. Assign a training directly or pull all trainings from a requirement."
            />
            <div class="flex items-center gap-2">
                <Button
                    v-if="canCreate && selectedCount > 0"
                    variant="outline"
                    data-testid="bulk-assign-btn"
                    @click="bulkOpen = true"
                >
                    Assign to selected ({{ selectedCount }})
                </Button>
                <Button v-if="canCreate" @click="openCreate()">+ New assignment</Button>
            </div>
        </div>

        <TrainingAssignmentPillLegend />

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                view-id="assignments"
                :default-columns="ASSIGNMENTS_COLUMNS"
                :rows="table.rows.value"
                :sort-key="activeColumnKey"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.user_id"
                @sort="onSort"
            >
                <template #lead-header>
                    <th class="w-10 px-2 py-2">
                        <Checkbox
                            :model-value="allOnPage"
                            aria-label="Select all on page"
                            @update:model-value="toggleAll"
                        />
                    </th>
                </template>
                <template #lead-cells="{ row }">
                    <td class="w-10 px-2 py-2">
                        <Checkbox
                            :model-value="isSelected(row.user_id)"
                            :aria-label="`Select ${row.name}`"
                            @update:model-value="() => toggleUser(row.user_id)"
                        />
                    </td>
                </template>

                <template #filters>
                    <div class="grid gap-1">
                        <Label for="filter_search" class="text-xs">Search trainings</Label>
                        <Input
                            id="filter_search"
                            :model-value="search"
                            type="search"
                            placeholder="Training name…"
                            class="h-8 w-56"
                            @update:model-value="onSearch"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label for="filter_user" class="text-xs">Filter by user</Label>
                        <Input
                            id="filter_user"
                            :model-value="userSearch"
                            type="search"
                            placeholder="Name, email, EE#, dept…"
                            class="h-8 w-56"
                            @update:model-value="onUserSearch"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Filter by requirement</Label>
                        <MultiSelectFilter
                            v-model:selected="requirementFilterIds"
                            v-model:mode="requirementFilterMode"
                            :options="requirementOptions"
                            label="requirements"
                            @update:selected="reloadNow"
                            @update:mode="reloadNow"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Filter by tag</Label>
                        <TagFilter
                            v-model:tag-ids="tagFilter"
                            v-model:mode="tagFilterMode"
                            placeholder="Any tag…"
                            @update:tag-ids="reloadNow"
                            @update:mode="reloadNow"
                        />
                    </div>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="h-8 rounded border border-input px-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="clearFilters"
                    >
                        Clear filters
                    </button>
                </template>

                <template #col-user="{ row }">
                    <div class="font-medium">{{ row.name }}</div>
                    <div v-if="row.email" class="text-xs text-muted-foreground">
                        {{ row.email }}
                    </div>
                </template>

                <template #col-supervisor="{ row }">
                    {{ row.supervisor_name ?? '—' }}
                </template>

                <template #col-tags="{ row }">
                    <TagsListCell :morphable-type="USER_TYPE" :morphable-id="row.user_id" />
                </template>

                <template #col-assignments="{ row }">
                    <div
                        v-if="requirementChipsFor(row).length"
                        class="mb-1 flex flex-wrap gap-1"
                    >
                        <RequirementAssignmentChip
                            v-for="ra in requirementChipsFor(row)"
                            :key="ra.requirement_id"
                            :row="ra"
                            :can-delete="canCreate"
                            @remove="removeRequirement(ra.user_id, ra.requirement_id)"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <TrainingAssignmentPill
                            v-for="ta in row.assignments"
                            :key="ta.id"
                            :row="ta"
                            :expiring-soon-days="orgSettings.expiringSoonDays"
                            @click="openView(ta)"
                        />
                        <button
                            v-if="canCreate"
                            type="button"
                            class="rounded-full border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                            title="Add a training assignment for this user"
                            @click="openCreate(row.user_id)"
                        >
                            + Add
                        </button>
                    </div>
                </template>

                <template #empty>No users match the current filters.</template>
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

        <TrainingAssignmentFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
            :initial-user-id="createUserId"
        />

        <BulkTrainingAssignModal
            v-if="canCreate"
            v-model:open="bulkOpen"
            :user-ids="[...selectedUserIds]"
            @applied="onBulkApplied"
        />
    </div>
</template>
