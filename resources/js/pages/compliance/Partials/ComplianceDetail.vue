<script setup lang="ts">
/*
 * Shared compliance detail screen — the "who's listed + their status" view
 * reached from a By-Requirement or Not-Required row. Header status chips (which
 * also filter), a searchable/tag-filterable user table with the profile
 * columns, and paging. Optionally selectable (lead checkboxes) with a #toolbar
 * slot for bulk actions and a #row-actions slot for per-row actions — the
 * owning page supplies those (e.g. ClassActionsBar). TrainingDetail stays its
 * own page but shares the same building blocks.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import { useRowSelection } from '@/composables/useRowSelection';
import { useServerTable } from '@/composables/useServerTable';
import type {
    ComplianceUsersQuery,
    ComplianceUserRow,
} from '@/stores/compliance';
import type { ServerTableResponse } from '@/composables/useServerTable';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { show as userShow } from '@/routes/users';
import { useTagsStore } from '@/stores/tags';

const USER_TYPE = 'App\\Models\\User';

const props = defineProps<{
    title: string;
    description: string;
    viewId: string;
    counts: Record<string, number>;
    statusChips: Array<{ key: string; label: string }>;
    fetcher: (
        params: ComplianceUsersQuery,
    ) => Promise<ServerTableResponse<ComplianceUserRow>>;
    // Requirement detail shows one row per training a user owes → label the rows.
    showTraining?: boolean;
    // Remap a stored status to a different badge (e.g. not-required's stored
    // 'overdue' should read as 'Expired'). Keyed by the raw row status.
    badgeStatusMap?: Record<string, ComplianceStatus>;
    // Render lead checkboxes + expose selection to the #toolbar slot.
    selectable?: boolean;
}>();

// Rows are unique by user + training (a requirement lists one row per training
// a user owes), so the selection key spans both.
const selection = useRowSelection<ComplianceUserRow>(
    (r) => `${r.user_id}::${r.training_id ?? ''}`,
);
const selectedUserIds = computed(() => [
    ...new Set(selection.items.value.map((r) => r.user_id)),
]);
const selectedTrainingIds = computed(() => [
    ...new Set(
        selection.items.value
            .map((r) => r.training_id)
            .filter((id): id is string => Boolean(id)),
    ),
]);

const tagsStore = useTagsStore();
const page = usePage();
const authUser = computed(
    () => page.props.auth.user as { org_id?: string } | null,
);

const columns = computed(() => [
    { key: 'name', label: 'User', sortable: false },
    ...(props.showTraining
        ? [{ key: 'training', label: 'Training', sortable: false }]
        : []),
    { key: 'status', label: 'Status', sortable: false },
    { key: 'employee_number', label: 'Employee #', sortable: false },
    { key: 'department', label: 'Department', sortable: false },
    { key: 'location', label: 'Location', sortable: false },
    { key: 'expires', label: 'Expires', sortable: false },
    { key: 'last_completed', label: 'Last completed', sortable: false },
    { key: 'tags', label: 'Tags', sortable: false },
]);

function badgeStatus(status: string): ComplianceStatus {
    return (props.badgeStatusMap?.[status] ?? status) as ComplianceStatus;
}

const allChips = computed(() => [
    { key: '', label: 'All' },
    ...props.statusChips,
]);

// #4 — tint each chip by what it represents so the eye lands on the problem.
const CHIP_TONES: Record<
    string,
    { active: string; idle: string; dot: string }
> = {
    overdue: { active: 'border-red-400 bg-red-100 text-red-900', idle: 'border-red-200 text-red-700 hover:bg-red-50', dot: 'bg-red-500' },
    expired: { active: 'border-red-400 bg-red-100 text-red-900', idle: 'border-red-200 text-red-700 hover:bg-red-50', dot: 'bg-red-500' },
    due_soon: { active: 'border-amber-400 bg-amber-100 text-amber-900', idle: 'border-amber-200 text-amber-700 hover:bg-amber-50', dot: 'bg-amber-500' },
    current: { active: 'border-emerald-400 bg-emerald-100 text-emerald-900', idle: 'border-emerald-200 text-emerald-700 hover:bg-emerald-50', dot: 'bg-emerald-500' },
    as_needed: { active: 'border-sky-400 bg-sky-100 text-sky-900', idle: 'border-sky-200 text-sky-700 hover:bg-sky-50', dot: 'bg-sky-500' },
    not_started: { active: 'border-border bg-muted text-foreground', idle: 'border-border text-muted-foreground hover:bg-muted', dot: 'bg-muted-foreground' },
};
function chipClasses(key: string, active: boolean): string {
    if (key === '') {
        return active
            ? 'border-primary bg-primary/10 text-foreground'
            : 'border-border text-muted-foreground hover:bg-muted';
    }
    const tone = CHIP_TONES[key];
    if (!tone) {
        return active
            ? 'border-primary bg-primary/10 text-foreground'
            : 'border-border text-muted-foreground hover:bg-muted';
    }
    return active ? tone.active : tone.idle;
}

const error = ref<string | null>(null);
const initialLoading = ref(true);
const search = ref('');
const statusFilter = ref('');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');

const table = useServerTable<ComplianceUserRow>(
    (params) =>
        props.fetcher({
            ...params,
            status: statusFilter.value || undefined,
            tags: tagFilter.value,
            tags_mode: tagFilterMode.value,
        }),
    { perPage: 25, sort: null, dir: 'desc' },
);

function chipCount(key: string): number {
    return key === ''
        ? (props.counts.total ?? 0)
        : (props.counts[key] ?? 0);
}
function setStatus(key: string): void {
    statusFilter.value = key;
    table.reload();
}
function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}
function reloadForTags(): void {
    table.reload();
}

// #6 — "Select all N matching" once a full page is picked. Pulls the matching
// rows (capped) from the same fetcher and selects them, so bulk actions can act
// on the whole filtered set, not just the visible page.
const SELECT_ALL_CAP = 1000;
const selectingAll = ref(false);
const canSelectAllMatching = computed(
    () =>
        props.selectable &&
        selection.count.value > 0 &&
        table.total.value > selection.count.value,
);
async function selectAllMatching(): Promise<void> {
    selectingAll.value = true;
    try {
        const cap = Math.min(table.total.value, SELECT_ALL_CAP);
        const res = await props.fetcher({
            page: 1,
            per_page: cap,
            sort: null,
            dir: table.dir.value,
            q: search.value,
            status: statusFilter.value || undefined,
            tags: tagFilter.value,
            tags_mode: tagFilterMode.value,
        });
        selection.clear();
        selection.toggleAllOnPage(res.data);
    } finally {
        selectingAll.value = false;
    }
}

// #5 — header counts come from the Inertia prop at load; refresh them (only that
// prop) when the tab regains focus, so they reflect classes/completions made
// elsewhere without a full reload.
function onVisible(): void {
    if (document.visibilityState === 'visible') {
        router.reload({ only: ['counts'] });
    }
}

watch(
    () => table.rows.value,
    (rows) => {
        for (const r of rows) {
            tagsStore.setAttached({ type: USER_TYPE, id: r.user_id }, r.tag_ids ?? []);
        }
    },
);

onMounted(async () => {
    if (authUser.value?.org_id) {
        tagsStore.subscribe(authUser.value.org_id);
    }
    tagsStore.loadLibrary().catch(() => {
        /* surfaced through the store */
    });

    document.addEventListener('visibilitychange', onVisible);

    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
    }
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', onVisible);
});
</script>

<template>
    <Head :title="`Compliance — ${title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="title" :description="description" />
            <div class="flex items-center gap-3">
                <slot name="header-actions" />
                <Link
                    href="/compliance"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    ← All compliance
                </Link>
            </div>
        </div>

        <!-- Status tallies double as filters; tinted by what they represent. -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in allChips"
                :key="chip.key || 'all'"
                type="button"
                :data-testid="`status-chip-${chip.key || 'all'}`"
                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm"
                :class="chipClasses(chip.key, statusFilter === chip.key)"
                @click="setStatus(chip.key)"
            >
                <span
                    v-if="chip.key && CHIP_TONES[chip.key]"
                    class="h-2 w-2 rounded-full"
                    :class="CHIP_TONES[chip.key].dot"
                />
                {{ chip.label }}
                <span class="ml-0.5 font-medium">{{ chipCount(chip.key) }}</span>
            </button>
        </div>

        <AsyncState :loading="initialLoading" :error="error">
            <!-- #6 — reach beyond the visible page when acting in bulk. -->
            <div
                v-if="selectable && selection.count.value > 0"
                data-testid="selection-bar"
                class="flex items-center gap-3 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm"
            >
                <span class="font-medium">{{ selection.count.value }} selected</span>
                <button
                    v-if="canSelectAllMatching"
                    type="button"
                    data-testid="select-all-matching"
                    class="text-primary hover:underline disabled:opacity-50"
                    :disabled="selectingAll"
                    @click="selectAllMatching"
                >
                    Select all {{ Math.min(table.total.value, SELECT_ALL_CAP) }} matching
                </button>
                <button
                    type="button"
                    data-testid="clear-selection"
                    class="text-muted-foreground hover:underline"
                    @click="selection.clear"
                >
                    Clear
                </button>
            </div>

            <DataTable
                :view-id="viewId"
                :default-columns="columns"
                :rows="table.rows.value"
                :sort-key="null"
                :sort-dir="table.dir.value"
                :row-key="(row) => `${row.user_id}::${row.training_id ?? ''}`"
            >
                <template #filters>
                    <div class="flex items-end gap-3">
                        <div class="grid gap-1">
                            <Label for="detail_q" class="text-xs">Search</Label>
                            <Input
                                id="detail_q"
                                :model-value="search"
                                placeholder="Search name, email, EE#, dept, location…"
                                class="h-8 w-72"
                                @update:model-value="onSearch"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Tags</Label>
                            <TagFilter
                                v-model:tag-ids="tagFilter"
                                v-model:mode="tagFilterMode"
                                placeholder="Any tag…"
                                @update:tag-ids="reloadForTags"
                                @update:mode="reloadForTags"
                            />
                        </div>
                        <slot
                            name="toolbar"
                            :selected-rows="selection.items.value"
                            :selected-user-ids="selectedUserIds"
                            :selected-training-ids="selectedTrainingIds"
                            :clear="selection.clear"
                        />
                    </div>
                </template>

                <template v-if="selectable" #lead-header>
                    <th class="w-10 px-2 py-2">
                        <Checkbox
                            :model-value="selection.allOnPage(table.rows.value)"
                            aria-label="Select all on page"
                            @update:model-value="
                                selection.toggleAllOnPage(table.rows.value)
                            "
                        />
                    </th>
                </template>
                <template v-if="selectable" #lead-cells="{ row }">
                    <td class="w-10 px-2 py-2">
                        <Checkbox
                            :model-value="selection.isSelected(row)"
                            :aria-label="`Select ${row.name ?? row.user_id}`"
                            @update:model-value="() => selection.toggle(row)"
                        />
                    </td>
                </template>

                <template v-if="$slots['row-actions']" #trail-header>
                    <th class="px-2 py-2"></th>
                </template>
                <template v-if="$slots['row-actions']" #trail-cells="{ row }">
                    <td class="px-2 py-2 text-right">
                        <slot name="row-actions" :row="row" />
                    </td>
                </template>

                <template #col-name="{ row }">
                    <Link
                        :href="userShow(row.user_id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ row.name ?? row.user_id }}
                    </Link>
                </template>

                <template #col-training="{ row }">
                    {{ row.training ?? '—' }}
                </template>

                <template #col-status="{ row }">
                    <ComplianceStatusBadge :status="badgeStatus(row.status)" />
                </template>

                <template #col-employee_number="{ row }">
                    {{ row.employee_number ?? '—' }}
                </template>
                <template #col-department="{ row }">
                    {{ row.department ?? '—' }}
                </template>
                <template #col-location="{ row }">
                    {{ row.location ?? '—' }}
                </template>
                <template #col-expires="{ row }">
                    {{ row.expires_at ?? '—' }}
                </template>
                <template #col-last_completed="{ row }">
                    {{ row.last_completed_at ?? 'never' }}
                </template>
                <template #col-tags="{ row }">
                    <TagsListCell :morphable-type="USER_TYPE" :morphable-id="row.user_id" />
                </template>

                <template #empty>No users match this filter.</template>
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
