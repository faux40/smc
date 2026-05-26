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
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { realtimeTabId } from '@/echo';
import AssignmentFormModal from '@/pages/assignments/Partials/AssignmentFormModal.vue';
import { page as assignmentsPage } from '@/routes/assignments';
import { useAssignmentsStore } from '@/stores/assignments';
import type { AssignmentRow } from '@/stores/assignments';
import { useRequirementsStore } from '@/stores/requirements';

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
}

const store = useAssignmentsStore();
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
const userFilter = ref('');
const requirementFilter = ref('');
const search = ref('');

type SortKey = 'user' | 'count';
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
    }

    try {
        await Promise.all([
            store.loadFor({}),
            requirements.load(),
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

// One row per user; their assignments become timing-coded pills. Filters
// narrow which assignments count toward a row, and a row drops out once it
// has no matching assignments left.
interface UserGroup {
    user_id: string;
    name: string;
    email: string | null;
    assignments: AssignmentRow[];
}

const userGroups = computed<UserGroup[]>(() => {
    const q = search.value.trim().toLowerCase();
    const byUser = new Map<string, AssignmentRow[]>();

    for (const a of store.rows) {
        if (userFilter.value && a.user_id !== userFilter.value) {
            continue;
        }

        if (
            requirementFilter.value &&
            a.requirement_id !== requirementFilter.value
        ) {
            continue;
        }

        const list = byUser.get(a.user_id) ?? [];
        list.push(a);
        byUser.set(a.user_id, list);
    }

    const groups: UserGroup[] = [];

    for (const [user_id, assignments] of byUser) {
        const name = userName(user_id) || user_id;
        const email = userById(user_id)?.email ?? null;

        // Search matches either the person or any of their requirements;
        // a person match keeps the whole row so the context stays intact.
        if (q) {
            const userMatch = `${name} ${email ?? ''}`
                .toLowerCase()
                .includes(q);
            const reqMatch = assignments.some((a) =>
                reqName(a).toLowerCase().includes(q),
            );

            if (!userMatch && !reqMatch) {
                continue;
            }
        }

        assignments.sort((a, b) => reqName(a).localeCompare(reqName(b)));
        groups.push({ user_id, name, email, assignments });
    }

    const dir = sortAsc.value ? 1 : -1;

    groups.sort((a, b) => {
        const cmp =
            sortKey.value === 'count'
                ? a.assignments.length - b.assignments.length
                : a.name.localeCompare(b.name);

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

const sortedUsers = computed(() =>
    [...userPicker.value].sort((a, b) =>
        (a.l_name ?? '').localeCompare(b.l_name ?? ''),
    ),
);
const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
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
                <Input
                    id="filter_search"
                    v-model="search"
                    type="search"
                    placeholder="Search user or requirement…"
                    class="h-8 w-64"
                    aria-label="Search assignments"
                />
            </div>
            <div class="grid gap-1">
                <Label for="filter_user" class="text-xs">Filter by user</Label>
                <select
                    id="filter_user"
                    v-model="userFilter"
                    class="rounded border border-input bg-background px-2 py-1 text-sm"
                >
                    <option value="">All users</option>
                    <option v-for="u in sortedUsers" :key="u.id" :value="u.id">
                        {{
                            [u.f_name, u.l_name].filter(Boolean).join(' ') ||
                            u.email ||
                            u.id
                        }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1">
                <Label for="filter_req" class="text-xs"
                    >Filter by requirement</Label
                >
                <select
                    id="filter_req"
                    v-model="requirementFilter"
                    class="rounded border border-input bg-background px-2 py-1 text-sm"
                >
                    <option value="">All requirements</option>
                    <option
                        v-for="r in sortedRequirements"
                        :key="r.id"
                        :value="r.id"
                    >
                        {{ r.name }}
                    </option>
                </select>
            </div>
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
