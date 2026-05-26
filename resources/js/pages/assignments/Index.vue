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
import { computed, onMounted, ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { realtimeTabId } from '@/echo';
import AssignmentFormModal from '@/pages/assignments/Partials/AssignmentFormModal.vue';
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

const userPicker = ref<UserPickerRow[]>([]);
const search = ref('');
const searchMode = ref<FilterMode>('and');
const userFilterIds = ref<string[]>([]);
const userFilterMode = ref<FilterMode>('or');
const requirementFilterIds = ref<string[]>([]);
const requirementFilterMode = ref<FilterMode>('or');
const tagFilter = ref<string[]>([]);
const tagFilterMode = ref<TagFilterMode>('and');

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

type SortKey = 'user' | 'count' | 'tags';
const sortKey = ref<SortKey>('user');
const sortAsc = ref(true);

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<AssignmentRow | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
        tagsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([
            store.loadFor({}),
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
    assignments: AssignmentRow[];
}

const userGroups = computed<UserGroup[]>(() => {
    const byUser = new Map<string, AssignmentRow[]>();

    for (const a of store.rows) {
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
        const name = userName(user_id) || user_id;
        const email = userById(user_id)?.email ?? null;

        if (
            !matchUser(user_id) ||
            !matchRequirements(assignments) ||
            !userMatchesTags(user_id) ||
            !matchSearch(name, email, assignments)
        ) {
            continue;
        }

        assignments.sort((a, b) => reqName(a).localeCompare(reqName(b)));
        groups.push({ user_id, name, email, assignments });
    }

    const dir = sortAsc.value ? 1 : -1;

    groups.sort((a, b) => {
        let cmp: number;

        if (sortKey.value === 'count') {
            cmp = a.assignments.length - b.assignments.length;
        } else if (sortKey.value === 'tags') {
            cmp = tagSignature(a.user_id).localeCompare(
                tagSignature(b.user_id),
            );
        } else {
            cmp = a.name.localeCompare(b.name);
        }

        return cmp * dir;
    });

    return groups;
});

const shownAssignmentCount = computed(() =>
    userGroups.value.reduce((n, g) => n + g.assignments.length, 0),
);

function toggleSort(key: SortKey): void {
    if (sortKey.value === key) {
        sortAsc.value = !sortAsc.value;
    } else {
        sortKey.value = key;
        sortAsc.value = true;
    }
}

function sortIndicator(key: SortKey): string {
    if (sortKey.value !== key) {
        return '';
    }

    return sortAsc.value ? '▲' : '▼';
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
                                <div class="font-medium">{{ group.name }}</div>
                                <div
                                    v-if="group.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ group.email }}
                                </div>
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
    </div>
</template>
