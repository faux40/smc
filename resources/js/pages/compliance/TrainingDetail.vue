<script setup lang="ts">
/*
 * Per-training compliance detail (reached by clicking a training on the
 * Compliance "By training" tab). Shows the training's status tallies (header
 * chips that also filter), the users assigned it (worst-status first, search +
 * status filter + paging), and lets a manager select users and assemble a
 * class for the training — reusing ClassFormModal + bulk enrollment.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import TagsListCell from '@/components/TagsListCell.vue';
import { useServerTable } from '@/composables/useServerTable';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import ComplianceStatusBadge from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';
import { showPage } from '@/routes/classes';
import { show as userShow } from '@/routes/users';
import { useClassesStore } from '@/stores/classes';
import { useComplianceStore } from '@/stores/compliance';
import type {
    ComplianceCounts,
    ComplianceUserRow,
} from '@/stores/compliance';
import { useTagsStore } from '@/stores/tags';

const USER_TYPE = 'App\\Models\\User';

interface Counts extends ComplianceCounts {
    total: number;
}

const props = defineProps<{
    training: { id: string; name: string };
    counts: Counts;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Compliance', href: '/compliance' }],
    },
});

const store = useComplianceStore();
const classes = useClassesStore();
const tagsStore = useTagsStore();
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
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

const STATUS_CHIPS: Array<{ key: string; label: string }> = [
    { key: '', label: 'All' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'due_soon', label: 'Due soon' },
    { key: 'not_started', label: 'Not started' },
    { key: 'current', label: 'Current' },
    { key: 'as_needed', label: 'As-needed' },
];

const error = ref<string | null>(null);
const initialLoading = ref(true);
const search = ref('');
const statusFilter = ref('');

const table = useServerTable<ComplianceUserRow>(
    (params) =>
        store.trainingUsers(props.training.id, {
            ...params,
            status: statusFilter.value || undefined,
        }),
    { perPage: 25, sort: null, dir: 'desc' },
);

function chipCount(key: string): number {
    if (key === '') return props.counts.total;
    return props.counts[key as keyof ComplianceCounts] ?? 0;
}

function setStatus(key: string): void {
    statusFilter.value = key;
    table.reload();
}

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

// ---- Selection + assemble a class -------------------------------------
const selected = ref<Set<string>>(new Set());
const selectedCount = computed(() => selected.value.size);
const isSelected = (id: string) => selected.value.has(id);
function toggle(id: string): void {
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selected.value = next;
}
const allOnPage = computed(
    () =>
        table.rows.value.length > 0 &&
        table.rows.value.every((r) => selected.value.has(r.user_id)),
);
function toggleAll(): void {
    const next = new Set(selected.value);
    if (allOnPage.value) {
        table.rows.value.forEach((r) => next.delete(r.user_id));
    } else {
        table.rows.value.forEach((r) => next.add(r.user_id));
    }
    selected.value = next;
}

const classModalOpen = ref(false);

async function onClassSaved(detail: { id: string }): Promise<void> {
    // Enroll the selected users onto the freshly created class, then go finish
    // scheduling/closing it on its detail page.
    if (selected.value.size > 0) {
        await classes.bulkEnroll(detail.id, {
            enroll: [...selected.value],
            unenroll: [],
        });
    }
    router.visit(showPage(detail.id));
}

// Hydrate the tags store from each fetched page so TagsListCell paints the
// attached pills without a per-row fetch (same pattern as the users list).
function hydrateTags(rows: ComplianceUserRow[]): void {
    for (const r of rows) {
        tagsStore.setAttached({ type: USER_TYPE, id: r.user_id }, r.tag_ids ?? []);
    }
}
watch(
    () => table.rows.value,
    (rows) => hydrateTags(rows),
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
    <Head :title="`Compliance — ${training.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="training.name"
                description="Compliance for this training. Filter by status, then select users to assemble a class."
            />
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
                v-for="chip in STATUS_CHIPS"
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
                view-id="compliance-training-detail"
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
                                placeholder="Search name, email, EE#, dept, location, tag…"
                                class="h-8 w-72"
                                @update:model-value="onSearch"
                            />
                        </div>
                        <Button
                            v-if="canManage"
                            type="button"
                            :disabled="selectedCount === 0"
                            data-testid="assemble-class"
                            @click="classModalOpen = true"
                        >
                            Create class with selected ({{ selectedCount }})
                        </Button>
                    </div>
                </template>

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
                            :aria-label="`Select ${row.name ?? row.user_id}`"
                            @update:model-value="() => toggle(row.user_id)"
                        />
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

                <template #col-status="{ row }">
                    <ComplianceStatusBadge
                        :status="(row.status as ComplianceStatus)"
                    />
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
                    <TagsListCell
                        :morphable-type="USER_TYPE"
                        :morphable-id="row.user_id"
                    />
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

        <ClassFormModal
            v-if="canManage"
            v-model:open="classModalOpen"
            :preset-training-ids="[training.id]"
            :preset-name="training.name"
            @saved="onClassSaved"
        />
    </div>
</template>
