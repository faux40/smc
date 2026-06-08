<script setup lang="ts">
/*
 * Training assignments index page (Phase D redesign).
 *
 * One row per user; pills are per (user, training) training assignments.
 * Legacy requirement-based assignments retired from this view.
 * Bulk assign returns in Phase E with the training-assignment model.
 */
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import FilterModeToggle from '@/components/FilterModeToggle.vue';
import type { FilterMode } from '@/components/FilterModeToggle.vue';
import Heading from '@/components/Heading.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import TrainingAssignmentPill from '@/components/TrainingAssignmentPill.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTableSort } from '@/composables/useTableSort';
import { realtimeTabId } from '@/echo';
import TrainingAssignmentFormModal from '@/pages/assignments/Partials/TrainingAssignmentFormModal.vue';
import { page as assignmentsPage } from '@/routes/assignments';
import { usePreferencesStore } from '@/stores/preferences';
import { useRequirementsStore } from '@/stores/requirements';
import { useTagsStore } from '@/stores/tags';
import { useTrainingAssignmentsStore } from '@/stores/trainingAssignments';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

const USER_TYPE = 'App\\Models\\User';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Assignments', href: assignmentsPage() }],
    },
});

interface UserPickerRow {
    id: string;
    f_name: string;
    l_name: string;
    email: string | null;
    tag_ids: string[];
    employee_number: string | null;
    department: string | null;
    location: string | null;
    job_title: string | null;
    supervisor_id: string | null;
    supervisor_name: string | null;
}

interface UserGroup {
    user_id: string;
    name: string;
    email: string | null;
    employee_number: string | null;
    department: string | null;
    location: string | null;
    job_title: string | null;
    supervisor_name: string | null;
    trainingAssignments: TrainingAssignmentRow[];
}

const ASSIGNMENTS_COLUMNS = [
    { key: 'user', label: 'User', sortable: true },
    { key: 'employee_number', label: 'Employee #', sortable: true },
    { key: 'job_title', label: 'Job title', sortable: true },
    { key: 'department', label: 'Department', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'supervisor', label: 'Supervisor', sortable: true },
    { key: 'tags', label: 'Tags', sortable: true },
    { key: 'assignments', label: 'Assignments', sortable: true },
];

const taStore = useTrainingAssignmentsStore();
const tagsStore = useTagsStore();
const requirements = useRequirementsStore();
const page = usePage();
const prefs = usePreferencesStore();

const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
            preferences?: import('@/stores/preferences').PrefsBlob | null;
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
const canDeassign = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const userPicker = ref<UserPickerRow[]>([]);
const search = ref('');
const searchMode = ref<FilterMode>('and');
const userFilterIds = ref<string[]>([]);
const userFilterMode = ref<FilterMode>('or');
const requirementFilterIds = ref<string[]>([]);
const requirementFilterMode = ref<FilterMode>('or');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');

const USER_FILTER_MODES: FilterMode[] = ['or', 'not'];

const hasActiveFilters = computed(
    () =>
        search.value.trim() !== '' ||
        userFilterIds.value.length > 0 ||
        requirementFilterIds.value.length > 0 ||
        tagFilter.value.length > 0,
);

function clearFilters(): void {
    search.value = '';
    searchMode.value = 'and';
    userFilterIds.value = [];
    userFilterMode.value = 'or';
    requirementFilterIds.value = [];
    requirementFilterMode.value = 'or';
    tagFilter.value = [];
    tagFilterMode.value = 'and';
}

const modalOpen = ref(false);
const modalMode = ref<'create' | 'view'>('create');
const editing = ref<TrainingAssignmentRow | null>(null);
const createUserId = ref<string | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

onMounted(async () => {
    prefs.ensureHydrated(authUser.value?.preferences ?? null);
    restoreSavedFilters();

    if (authUser.value?.org_id) {
        taStore.subscribe(authUser.value.org_id);
        tagsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([
            taStore.loadFor({}),
            requirements.load(),
            tagsStore.loadLibrary(),
            loadUsers(),
        ]);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

async function loadUsers(): Promise<void> {
    const { data } = await axios.get<UserPickerRow[]>('/api/users', {
        headers: defaultHeaders(),
    });
    userPicker.value = data;

    for (const u of data) {
        tagsStore.setAttached({ type: USER_TYPE, id: u.id }, u.tag_ids ?? []);
    }
}

const userTagIds = (userId: string): string[] =>
    tagsStore.attachedTagsFor({ type: USER_TYPE, id: userId }).map((t) => t.id);

const tagSignature = (userId: string): string =>
    tagsStore
        .attachedTagsFor({ type: USER_TYPE, id: userId })
        .map((t) => t.name.toLowerCase())
        .sort()
        .join(',');

function userMatchesTags(userId: string): boolean {
    if (tagFilter.value.length === 0) return true;

    const ids = new Set(userTagIds(userId));

    if (tagFilterMode.value === 'or') return tagFilter.value.some((id) => ids.has(id));
    if (tagFilterMode.value === 'not') return !tagFilter.value.some((id) => ids.has(id));

    return tagFilter.value.every((id) => ids.has(id));
}

const userById = (id: string) => userPicker.value.find((u) => u.id === id);

const userName = (id: string): string => {
    const u = userById(id);

    return u
        ? [u.f_name, u.l_name].filter(Boolean).join(' ') || u.email || ''
        : '';
};

function matchUser(userId: string): boolean {
    if (userFilterIds.value.length === 0) return true;

    const inSet = userFilterIds.value.includes(userId);

    return userFilterMode.value === 'not' ? !inSet : inSet;
}

function matchRequirements(tas: TrainingAssignmentRow[]): boolean {
    const sel = requirementFilterIds.value;

    if (sel.length === 0) return true;

    // Collect all requirement IDs from active sources across all TAs.
    const haveReqs = new Set(
        tas.flatMap((ta) =>
            ta.active_sources
                .filter((s) => s.sourceable_type !== null)
                .map((s) => s.sourceable_id!)
                .filter(Boolean),
        ),
    );

    if (requirementFilterMode.value === 'or') return sel.some((id) => haveReqs.has(id));
    if (requirementFilterMode.value === 'not') return !sel.some((id) => haveReqs.has(id));

    return sel.every((id) => haveReqs.has(id));
}

function matchSearch(
    name: string,
    email: string | null,
    tas: TrainingAssignmentRow[],
): boolean {
    const words = search.value
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) return true;

    const hay =
        `${name} ${email ?? ''} ${tas.map((ta) => ta.name).join(' ')}`.toLowerCase();

    if (searchMode.value === 'or') return words.some((w) => hay.includes(w));
    if (searchMode.value === 'not') return !words.some((w) => hay.includes(w));

    return words.every((w) => hay.includes(w));
}

const filteredGroups = computed<UserGroup[]>(() => {
    const byUser = new Map<string, TrainingAssignmentRow[]>();

    for (const ta of taStore.rows) {
        const list = byUser.get(ta.user_id) ?? [];
        list.push(ta);
        byUser.set(ta.user_id, list);
    }

    const userIds = new Set<string>(userPicker.value.map((u) => u.id));

    for (const id of byUser.keys()) {
        userIds.add(id);
    }

    const groups: UserGroup[] = [];

    for (const user_id of userIds) {
        const trainingAssignments = byUser.get(user_id) ?? [];
        const u = userById(user_id);
        const name = userName(user_id) || user_id;
        const email = u?.email ?? null;

        if (
            !matchUser(user_id) ||
            !matchRequirements(trainingAssignments) ||
            !userMatchesTags(user_id) ||
            !matchSearch(name, email, trainingAssignments)
        ) {
            continue;
        }

        trainingAssignments.sort((a, b) => a.name.localeCompare(b.name));
        groups.push({
            user_id,
            name,
            email,
            employee_number: u?.employee_number ?? null,
            department: u?.department ?? null,
            location: u?.location ?? null,
            job_title: u?.job_title ?? null,
            supervisor_name: u?.supervisor_name ?? null,
            trainingAssignments,
        });
    }

    return groups;
});

const { sortKey, sortDir, toggleSort, sorted: userGroups } =
    useTableSort<UserGroup>(
        () => filteredGroups.value,
        {
            user: (g) => g.name,
            assignments: (g) => g.trainingAssignments.length,
            tags: (g) => tagSignature(g.user_id),
            employee_number: (g) => g.employee_number,
            department: (g) => g.department,
            location: (g) => g.location,
            job_title: (g) => g.job_title,
            supervisor: (g) => g.supervisor_name,
        },
        { key: 'user', dir: 'asc' },
    );

function snapshotFilters() {
    return {
        search: search.value,
        searchMode: searchMode.value,
        userFilterIds: userFilterIds.value,
        userFilterMode: userFilterMode.value,
        requirementFilterIds: requirementFilterIds.value,
        requirementFilterMode: requirementFilterMode.value,
        tagFilter: tagFilter.value,
        tagFilterMode: tagFilterMode.value,
    };
}

watch(
    [
        search,
        searchMode,
        userFilterIds,
        userFilterMode,
        requirementFilterIds,
        requirementFilterMode,
        tagFilter,
        tagFilterMode,
    ],
    () => prefs.update('assignments', { filters: snapshotFilters() }),
    { deep: true },
);

function restoreSavedFilters(): void {
    const saved = prefs.view('assignments').filters as
        | Partial<ReturnType<typeof snapshotFilters>>
        | undefined;

    if (!saved) return;

    if (typeof saved.search === 'string') search.value = saved.search;
    if (saved.searchMode) searchMode.value = saved.searchMode;
    if (Array.isArray(saved.userFilterIds)) userFilterIds.value = saved.userFilterIds;
    if (saved.userFilterMode) userFilterMode.value = saved.userFilterMode;
    if (Array.isArray(saved.requirementFilterIds))
        requirementFilterIds.value = saved.requirementFilterIds;
    if (saved.requirementFilterMode)
        requirementFilterMode.value = saved.requirementFilterMode;
    if (Array.isArray(saved.tagFilter)) tagFilter.value = saved.tagFilter;
    if (saved.tagFilterMode) tagFilterMode.value = saved.tagFilterMode;
}

const shownAssignmentCount = computed(() =>
    userGroups.value.reduce((n, g) => n + g.trainingAssignments.length, 0),
);

const userOptions = computed(() =>
    [...userPicker.value]
        .sort((a, b) => (a.l_name ?? '').localeCompare(b.l_name ?? ''))
        .map((u) => ({
            id: u.id,
            label:
                [u.f_name, u.l_name].filter(Boolean).join(' ') ||
                u.email ||
                u.id,
        })),
);
const requirementOptions = computed(() =>
    [...requirements.library]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((r) => ({ id: r.id, label: r.name })),
);

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

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}
</script>

<template>
    <Head title="Assignments" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Assignments"
                description="Per-(user, training) compliance records. Assign a training directly or pull all trainings from a requirement."
            />
            <Button v-if="canCreate" @click="openCreate()">+ New assignment</Button>
        </div>

        <AsyncState
            :loading="loading"
            :error="error"
            :empty="userGroups.length === 0"
        >
            <template #empty>
                <div
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No assignments match the current filter.
                    <span v-if="canCreate && taStore.rows.length === 0">
                        Click "+ New assignment" to create one.
                    </span>
                </div>
            </template>

            <DataTable
                view-id="assignments"
                :default-columns="ASSIGNMENTS_COLUMNS"
                :rows="userGroups"
                :sort-key="sortKey"
                :sort-dir="sortDir"
                :row-key="(row) => row.user_id"
                @sort="toggleSort"
            >
                <template #filters>
                    <div class="grid gap-1">
                        <Label for="filter_search" class="text-xs">Search</Label>
                        <div class="flex items-center gap-1">
                            <Input
                                id="filter_search"
                                v-model="search"
                                type="search"
                                placeholder="Search user or training…"
                                class="h-8 w-64"
                                aria-label="Search assignments"
                            />
                            <FilterModeToggle
                                v-if="search.trim() !== ''"
                                v-model:mode="searchMode"
                            />
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Filter by user</Label>
                        <MultiSelectFilter
                            v-model:selected="userFilterIds"
                            v-model:mode="userFilterMode"
                            :options="userOptions"
                            :modes="USER_FILTER_MODES"
                            label="users"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Filter by requirement</Label>
                        <MultiSelectFilter
                            v-model:selected="requirementFilterIds"
                            v-model:mode="requirementFilterMode"
                            :options="requirementOptions"
                            label="requirements"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Filter by tag</Label>
                        <TagFilter
                            v-model:tag-ids="tagFilter"
                            v-model:mode="tagFilterMode"
                            placeholder="Any tag…"
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
                    <span class="text-xs text-muted-foreground">
                        {{ userGroups.length }} users ·
                        {{ shownAssignmentCount }} of
                        {{ taStore.rows.length }} assignments
                    </span>
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
                    <TagsListCell
                        :morphable-type="USER_TYPE"
                        :morphable-id="row.user_id"
                    />
                </template>

                <template #col-assignments="{ row }">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <TrainingAssignmentPill
                            v-for="ta in row.trainingAssignments"
                            :key="ta.id"
                            :row="ta"
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
            </DataTable>
        </AsyncState>

        <TrainingAssignmentFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
            :initial-user-id="createUserId"
        />
    </div>
</template>
