<script setup lang="ts">
/*
 * Shared compliance detail screen — the read-only "who's listed + their status"
 * view reached from a By-Requirement or Not-Required row. Header status chips
 * (which also filter), a searchable/tag-filterable user table with the profile
 * columns, and paging. The owning page binds the data fetcher + the chip set;
 * TrainingDetail stays its own (richer) page because it also assembles classes.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
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
}>();

const tagsStore = useTagsStore();
const page = usePage();
const authUser = computed(
    () => page.props.auth.user as { org_id?: string } | null,
);

const COLUMNS = [
    { key: 'name', label: 'User', sortable: false },
    { key: 'status', label: 'Status', sortable: false },
    { key: 'employee_number', label: 'Employee #', sortable: false },
    { key: 'department', label: 'Department', sortable: false },
    { key: 'location', label: 'Location', sortable: false },
    { key: 'expires', label: 'Expires', sortable: false },
    { key: 'last_completed', label: 'Last completed', sortable: false },
    { key: 'tags', label: 'Tags', sortable: false },
];

const allChips = computed(() => [
    { key: '', label: 'All' },
    ...props.statusChips,
]);

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
    <Head :title="`Compliance — ${title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="title" :description="description" />
            <Link
                href="/compliance"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← All compliance
            </Link>
        </div>

        <!-- Status tallies double as filters. -->
        <div class="flex flex-wrap gap-2">
            <button
                v-for="chip in allChips"
                :key="chip.key || 'all'"
                type="button"
                :data-testid="`status-chip-${chip.key || 'all'}`"
                class="rounded-full border px-3 py-1 text-sm"
                :class="
                    statusFilter === chip.key
                        ? 'border-primary bg-primary/10 text-foreground'
                        : 'border-border text-muted-foreground hover:bg-muted'
                "
                @click="setStatus(chip.key)"
            >
                {{ chip.label }}
                <span class="ml-1 font-medium">{{ chipCount(chip.key) }}</span>
            </button>
        </div>

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                :view-id="viewId"
                :default-columns="COLUMNS"
                :rows="table.rows.value"
                :sort-key="null"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.user_id"
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
                    </div>
                </template>

                <template #col-name="{ row }">
                    <Link
                        :href="userShow(row.user_id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ row.name ?? row.user_id }}
                    </Link>
                </template>

                <template #col-status="{ row }">
                    <ComplianceStatusBadge :status="(row.status as ComplianceStatus)" />
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
