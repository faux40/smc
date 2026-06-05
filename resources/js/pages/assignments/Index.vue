<script setup lang="ts">
/*
 * Manual single-assignment admin page (Phase 13.2).
 *
 * Loads the full org assignment list and renders it with filters.
 * "+ New assignment" opens AssignmentFormModal for one-off entry. The
 * bulk flow lives at /workflows/bulk-assignment.
 */
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AssignmentPill from '@/components/AssignmentPill.vue';
import AsyncState from '@/components/AsyncState.vue';
import FilterModeToggle from '@/components/FilterModeToggle.vue';
import type { FilterMode } from '@/components/FilterModeToggle.vue';
import Heading from '@/components/Heading.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTableSort } from '@/composables/useTableSort';
import { realtimeTabId } from '@/echo';
import AssignmentFormModal from '@/pages/assignments/Partials/AssignmentFormModal.vue';
import BulkAssignmentsModal from '@/pages/assignments/Partials/BulkAssignmentsModal.vue';
import { page as assignmentsPage } from '@/routes/assignments';
import { useAssignmentsStore } from '@/stores/assignments';
import type { AssignmentRow } from '@/stores/assignments';
import { useRequirementsStore } from '@/stores/requirements';
import { useTagsStore } from '@/stores/tags';

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

const store = useAssignmentsStore();
const tagsStore = useTagsStore();
const requirements = useRequirementsStore();
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
const canCreate = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);
// De-assign maps to the delete policy — Admin+ only (not Manager).
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
const showExpired = ref(false);

// Users can only be matched with OR/NONE — a row is one person, so "must
// have all" is degenerate.
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
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<AssignmentRow | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

// Row selection for bulk assign / de-assign.
const selectedUserIds = ref<string[]>([]);
const bulkOpen = ref(false);
const bulkMode = ref<'assign' | 'deassign'>('assign');

// Today's date, refreshed periodically so assignments drop off as their
// end_date passes even if no broadcast fires (pure time-based expiry).
const nowDate = ref(new Date().toISOString().slice(0, 10));
let expiryTimer: ReturnType<typeof setInterval> | undefined;

onUnmounted(() => {
    if (expiryTimer) {
        clearInterval(expiryTimer);
    }
});

onMounted(async () => {
    expiryTimer = setInterval(() => {
        nowDate.value = new Date().toISOString().slice(0, 10);
    }, 60_000);

    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
        tagsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([
            store.loadFor({}, showExpired.value),
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

    // Seed the tags store so each row's TagsListCell shows attached pills on
    // first paint; TagAttached/TagDetached broadcasts keep it in sync after.
    for (const u of data) {
        tagsStore.setAttached({ type: USER_TYPE, id: u.id }, u.tag_ids ?? []);
    }
}

const userTagIds = (userId: string): string[] =>
    tagsStore.attachedTagsFor({ type: USER_TYPE, id: userId }).map((t) => t.id);

// Sorted tag-name signature for the "Tags" column sort; untagged users get
// an empty string (sort first asc / last desc).
const tagSignature = (userId: string): string =>
    tagsStore
        .attachedTagsFor({ type: USER_TYPE, id: userId })
        .map((t) => t.name.toLowerCase())
        .sort()
        .join(',');

function userMatchesTags(userId: string): boolean {
    if (tagFilter.value.length === 0) {
        return true;
    }

    const ids = new Set(userTagIds(userId));

    if (tagFilterMode.value === 'or') {
        return tagFilter.value.some((id) => ids.has(id));
    }

    if (tagFilterMode.value === 'not') {
        return !tagFilter.value.some((id) => ids.has(id));
    }

    return tagFilter.value.every((id) => ids.has(id)); // 'and'
}

const userById = (id: string) => userPicker.value.find((u) => u.id === id);
const requirementById = (id: string) =>
    requirements.library.find((r) => r.id === id);

const userName = (id: string): string => {
    const u = userById(id);

    return u
        ? [u.f_name, u.l_name].filter(Boolean).join(' ') || u.email || ''
        : '';
};
const reqName = (row: AssignmentRow): string =>
    requirementById(row.requirement_id)?.name ?? row.name ?? '';

// Past end_date — hidden unless "show expired" is on, where it renders
// greyed + struck-through. nowDate ticks so this stays current over time.
const isExpired = (row: AssignmentRow): boolean =>
    row.end_date !== null && row.end_date < nowDate.value;

// All four filters narrow which user *rows* show (pills always render the
// user's full assignment set). Each supports &/||/! over its selected set.

function matchUser(userId: string): boolean {
    if (userFilterIds.value.length === 0) {
        return true;
    }

    const inSet = userFilterIds.value.includes(userId);

    // 'and' is degenerate for a single-valued field → behaves like 'or'.
    return userFilterMode.value === 'not' ? !inSet : inSet;
}

function matchRequirements(assignments: AssignmentRow[]): boolean {
    const sel = requirementFilterIds.value;

    if (sel.length === 0) {
        return true;
    }

    const have = new Set(assignments.map((a) => a.requirement_id));

    if (requirementFilterMode.value === 'or') {
        return sel.some((id) => have.has(id));
    }

    if (requirementFilterMode.value === 'not') {
        return !sel.some((id) => have.has(id));
    }

    return sel.every((id) => have.has(id)); // 'and' — has all selected
}

function matchSearch(
    name: string,
    email: string | null,
    assignments: AssignmentRow[],
): boolean {
    const words = search.value
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean);

    if (words.length === 0) {
        return true;
    }

    const hay =
        `${name} ${email ?? ''} ${assignments.map((a) => reqName(a)).join(' ')}`.toLowerCase();

    if (searchMode.value === 'or') {
        return words.some((w) => hay.includes(w));
    }

    if (searchMode.value === 'not') {
        return !words.some((w) => hay.includes(w));
    }

    return words.every((w) => hay.includes(w)); // 'and' — all words present
}

// One row per user — every active user shows, with or without assignments
// (this is also where you assign, via the per-row "+ Add"). A user's
// assignments become timing-coded pills.
interface UserGroup {
    user_id: string;
    name: string;
    email: string | null;
    employee_number: string | null;
    department: string | null;
    location: string | null;
    job_title: string | null;
    supervisor_name: string | null;
    assignments: AssignmentRow[];
}

const filteredGroups = computed<UserGroup[]>(() => {
    const byUser = new Map<string, AssignmentRow[]>();

    for (const a of store.rows) {
        // Expired (past end_date) rows are hidden unless "show expired" is on.
        if (isExpired(a) && !showExpired.value) {
            continue;
        }

        const list = byUser.get(a.user_id) ?? [];
        list.push(a);
        byUser.set(a.user_id, list);
    }

    // Every active user, plus any user that has assignments but isn't in the
    // active picker (e.g. disabled) so their rows don't silently vanish.
    const userIds = new Set<string>(userPicker.value.map((u) => u.id));

    for (const id of byUser.keys()) {
        userIds.add(id);
    }

    const groups: UserGroup[] = [];

    for (const user_id of userIds) {
        const assignments = byUser.get(user_id) ?? [];
        const u = userById(user_id);
        const name = userName(user_id) || user_id;
        const email = u?.email ?? null;

        if (
            !matchUser(user_id) ||
            !matchRequirements(assignments) ||
            !userMatchesTags(user_id) ||
            !matchSearch(name, email, assignments)
        ) {
            continue;
        }

        assignments.sort((a, b) => reqName(a).localeCompare(reqName(b)));
        groups.push({
            user_id,
            name,
            email,
            employee_number: u?.employee_number ?? null,
            department: u?.department ?? null,
            location: u?.location ?? null,
            job_title: u?.job_title ?? null,
            supervisor_name: u?.supervisor_name ?? null,
            assignments,
        });
    }

    return groups;
});

// Sorting via the shared composable (empties last, case-insensitive). `tags`
// sorts by the row's tag signature; `count` by the number of assignments.
const { sortKey, sortDir, toggleSort, sorted: userGroups } =
    useTableSort<UserGroup>(
        () => filteredGroups.value,
        {
            user: (g) => g.name,
            count: (g) => g.assignments.length,
            tags: (g) => tagSignature(g.user_id),
            employee_number: (g) => g.employee_number,
            department: (g) => g.department,
            location: (g) => g.location,
            job_title: (g) => g.job_title,
            supervisor: (g) => g.supervisor_name,
        },
        { key: 'user', dir: 'asc' },
    );

const shownAssignmentCount = computed(() =>
    userGroups.value.reduce((n, g) => n + g.assignments.length, 0),
);

// ---- Bulk row selection ----
const visibleUserIds = computed(() => userGroups.value.map((g) => g.user_id));
const allVisibleSelected = computed(
    () =>
        visibleUserIds.value.length > 0 &&
        visibleUserIds.value.every((id) => selectedUserIds.value.includes(id)),
);

function isUserSelected(id: string): boolean {
    return selectedUserIds.value.includes(id);
}

function toggleUser(id: string): void {
    selectedUserIds.value = isUserSelected(id)
        ? selectedUserIds.value.filter((x) => x !== id)
        : [...selectedUserIds.value, id];
}

function toggleSelectAll(): void {
    if (allVisibleSelected.value) {
        const visible = new Set(visibleUserIds.value);
        selectedUserIds.value = selectedUserIds.value.filter(
            (id) => !visible.has(id),
        );
    } else {
        selectedUserIds.value = [
            ...new Set([...selectedUserIds.value, ...visibleUserIds.value]),
        ];
    }
}

function openBulk(mode: 'assign' | 'deassign'): void {
    bulkMode.value = mode;
    bulkOpen.value = true;
}

async function onBulkApplied(): Promise<void> {
    await store.reload(showExpired.value);
    selectedUserIds.value = [];
}

// Toggling "show expired" re-fetches with/without the historical expired
// rows (live edits are already in the store; this pulls the rest).
watch(showExpired, (v) => {
    store.reload(v).catch((e) => {
        error.value = (e as Error).message;
    });
});

function sortIndicator(key: string): string {
    if (sortKey.value !== key) {
        return '';
    }

    return sortDir.value === 'asc' ? '▲' : '▼';
}

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

// Pre-selected user for the per-row quick-add ("+ Add"); null for the
// top-level "+ New assignment".
const createUserId = ref<string | null>(null);

const openCreate = (userId: string | null = null) => {
    modalMode.value = 'create';
    editing.value = null;
    createUserId.value = userId;
    modalOpen.value = true;
};

const openEdit = (row: AssignmentRow) => {
    modalMode.value = 'edit';
    editing.value = row;
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
                description="Per-(user, requirement) compliance timing records. Create one at a time here; use Bulk assign for tag-driven cross-products."
            />
            <Button v-if="canCreate" @click="openCreate"
                >+ New assignment</Button
            >
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1">
                <Label for="filter_search" class="text-xs">Search</Label>
                <div class="flex items-center gap-1">
                    <Input
                        id="filter_search"
                        v-model="search"
                        type="search"
                        placeholder="Search user or requirement…"
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
            <label
                class="inline-flex h-8 cursor-pointer items-center gap-2 self-end text-sm"
            >
                <Checkbox v-model="showExpired" />
                Show expired
            </label>
            <button
                v-if="hasActiveFilters"
                type="button"
                class="h-8 rounded border border-input px-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                @click="clearFilters"
            >
                Clear filters
            </button>
            <span class="text-xs text-muted-foreground">
                {{ userGroups.length }} users · {{ shownAssignmentCount }} of
                {{ store.rows.length }}
                assignments
            </span>
        </div>

        <div
            class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground"
        >
            <span>Each dot is one requirement element, by timing:</span>
            <span class="inline-flex items-center gap-1">
                <span
                    class="h-2.5 w-2.5 rounded-full bg-emerald-400 ring-1 ring-emerald-300 ring-inset dark:bg-emerald-500"
                />
                Repeating
            </span>
            <span class="inline-flex items-center gap-1">
                <span
                    class="h-2.5 w-2.5 rounded-full bg-sky-400 ring-1 ring-sky-300 ring-inset dark:bg-sky-500"
                />
                Initial-only
            </span>
            <span class="inline-flex items-center gap-1">
                <span
                    class="h-2.5 w-2.5 rounded-full bg-yellow-400 ring-1 ring-yellow-300 ring-inset dark:bg-yellow-500"
                />
                As-needed
            </span>
            <span class="inline-flex items-center gap-1">
                <span
                    class="h-2.5 w-2.5 rounded-full bg-neutral-300 ring-1 ring-neutral-300 ring-inset dark:bg-neutral-500"
                />
                No timing set
            </span>
        </div>

        <div
            v-if="selectedUserIds.length > 0"
            class="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/40 p-2"
        >
            <span class="text-sm font-medium">
                {{ selectedUserIds.length }} selected
            </span>
            <Button v-if="canCreate" size="sm" @click="openBulk('assign')">
                Assign requirements
            </Button>
            <Button
                v-if="canDeassign"
                size="sm"
                variant="outline"
                @click="openBulk('deassign')"
            >
                De-assign requirements
            </Button>
            <button
                type="button"
                class="text-xs text-muted-foreground hover:text-foreground hover:underline"
                @click="selectedUserIds = []"
            >
                Clear selection
            </button>
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
                    <span v-if="canCreate && store.rows.length === 0">
                        Click "+ New assignment" to create one.
                    </span>
                </div>
            </template>

            <div class="overflow-hidden rounded-md border border-border">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="w-10 px-4 py-2 text-left align-top">
                                <Checkbox
                                    :model-value="allVisibleSelected"
                                    aria-label="Select all"
                                    @update:model-value="toggleSelectAll"
                                />
                            </th>
                            <th
                                class="w-64 px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('user')"
                                >
                                    User {{ sortIndicator('user') }}
                                </button>
                            </th>
                            <th
                                class="px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="whitespace-nowrap hover:underline"
                                    @click="toggleSort('employee_number')"
                                >
                                    Employee #
                                    {{ sortIndicator('employee_number') }}
                                </button>
                            </th>
                            <th
                                class="px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="whitespace-nowrap hover:underline"
                                    @click="toggleSort('job_title')"
                                >
                                    Job title {{ sortIndicator('job_title') }}
                                </button>
                            </th>
                            <th
                                class="px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('department')"
                                >
                                    Department
                                    {{ sortIndicator('department') }}
                                </button>
                            </th>
                            <th
                                class="px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('location')"
                                >
                                    Location {{ sortIndicator('location') }}
                                </button>
                            </th>
                            <th
                                class="px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('supervisor')"
                                >
                                    Supervisor
                                    {{ sortIndicator('supervisor') }}
                                </button>
                            </th>
                            <th
                                class="w-72 px-4 py-2 text-left align-top font-medium"
                            >
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('tags')"
                                >
                                    Tags {{ sortIndicator('tags') }}
                                </button>
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                <button
                                    type="button"
                                    class="hover:underline"
                                    @click="toggleSort('count')"
                                >
                                    Assignments {{ sortIndicator('count') }}
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="group in userGroups"
                            :key="group.user_id"
                            class="align-top"
                        >
                            <td class="px-4 py-3">
                                <Checkbox
                                    :model-value="isUserSelected(group.user_id)"
                                    :aria-label="`Select ${group.name}`"
                                    @update:model-value="
                                        toggleUser(group.user_id)
                                    "
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ group.name }}</div>
                                <div
                                    v-if="group.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ group.email }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ group.employee_number ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ group.job_title ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ group.department ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ group.location ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                {{ group.supervisor_name ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <TagsListCell
                                    :morphable-type="USER_TYPE"
                                    :morphable-id="group.user_id"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <AssignmentPill
                                        v-for="a in group.assignments"
                                        :key="a.id"
                                        :label="reqName(a)"
                                        :summary="a.element_timing"
                                        :expired="isExpired(a)"
                                        @click="openEdit(a)"
                                    />
                                    <button
                                        v-if="canCreate"
                                        type="button"
                                        class="rounded-full border border-dashed border-border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                                        title="Add an assignment for this user"
                                        @click="openCreate(group.user_id)"
                                    >
                                        + Add
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AsyncState>

        <AssignmentFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
            :initial-user-id="createUserId"
        />

        <BulkAssignmentsModal
            v-model:open="bulkOpen"
            :mode="bulkMode"
            :user-ids="selectedUserIds"
            @applied="onBulkApplied"
        />
    </div>
</template>
