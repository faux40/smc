<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import TableColumnsMenu from '@/components/TableColumnsMenu.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import { useTableFilter } from '@/composables/useTableFilter';
import { useTableSort } from '@/composables/useTableSort';
import { useTableView } from '@/composables/useTableView';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import { index, show as userShow } from '@/routes/users';
import { usePreferencesStore, type PrefsBlob } from '@/stores/preferences';
import { useTagsStore } from '@/stores/tags';
import { useUsersStore } from '@/stores/users';
import type { UserRow } from '@/stores/users';

type Filters = {
    q: string;
    role: string;
    include_disabled: boolean;
    tags: string[];
    tags_mode: TagFilterMode;
};

type AuthUser = {
    id: string;
    org_id: string;
    isOwner?: boolean;
    isSuperAdmin?: boolean;
    isAdmin?: boolean;
    preferences?: PrefsBlob | null;
};

const props = defineProps<{
    users: UserRow[];
    filters: Filters;
    can_create: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: '/users' }],
    },
});

const store = useUsersStore();
const tagsStore = useTagsStore();
const page = usePage();
const authUser = page.props.auth.user as AuthUser;

// Client-side sort over the in-memory list (server-side filters above produce
// the set; this orders it). Empties sort last; defaults to last-name ascending.
const { sortKey, sortDir, toggleSort, sorted } = useTableSort<UserRow>(
    () => store.users,
    {
        name: (u) => `${u.l_name} ${u.f_name}`,
        email: (u) => u.email,
        role: (u) => u.role,
        status: (u) => u.status,
        job_title: (u) => u.job_title,
        employee_number: (u) => u.employee_number,
        department: (u) => u.department,
        location: (u) => u.location,
        supervisor: (u) => u.supervisor_name,
    },
    { key: 'name', dir: 'asc' },
);

// Per-user column control (show/hide + horizontal order), persisted via the
// prefs store. Actions + Tags are fixed utility columns, not in this set.
const prefs = usePreferencesStore();
const {
    columns: columnDefs,
    visibleColumns,
    toggle: toggleColumn,
    move: moveColumn,
} = useTableView('users', [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'job_title', label: 'Job title', sortable: true },
    { key: 'employee_number', label: 'Employee #', sortable: true },
    { key: 'department', label: 'Department', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'supervisor', label: 'Supervisor', sortable: true },
]);

// Plain-text cell value for the non-bespoke columns (name/role/status render
// their own markup in the template).
function cellText(u: UserRow, key: string): string {
    const value =
        key === 'supervisor'
            ? u.supervisor_name
            : key === 'email'
              ? u.email
              : key === 'job_title'
                ? u.job_title
                : key === 'employee_number'
                  ? u.employee_number
                  : key === 'department'
                    ? u.department
                    : key === 'location'
                      ? u.location
                      : null;

    return value ?? '—';
}

// TagsListCell on each row reads from `tagsStore.attached` keyed by
// (morphableType, morphableId). Push the eager-loaded ids into that
// map so the first paint already shows attached pills; subsequent
// TagAttached / TagDetached broadcasts keep it in sync.
function hydrateTagAttachments(rows: UserRow[]): void {
    for (const u of rows) {
        tagsStore.setAttached(
            { type: 'App\\Models\\User', id: u.id },
            u.tag_ids ?? [],
        );
    }
}

// reka-ui's <Select> primitive rejects empty-string values on its
// items (would clear the v-model and show the placeholder). Use a
// sentinel for the "All roles" option and strip it back out before
// the request goes to the backend.
const ALL_ROLES = '__all';

type UserFilters = {
    q: string;
    role: string; // '' = all roles
    include_disabled: boolean;
    tags: string[];
    tags_mode: TagFilterMode;
};

// Server-side filtering relayed through the prefs store: applies the query
// (Inertia reload) + saves the filter view, restored on a clean visit.
const { params: filters, commit, restoreSaved } = useTableFilter<UserFilters>(
    'users',
    {
        q: props.filters.q,
        role: props.filters.role,
        include_disabled: props.filters.include_disabled,
        tags: props.filters.tags ?? [],
        tags_mode: props.filters.tags_mode ?? 'and',
    },
    (p) =>
        router.get(
            index().url,
            {
                q: p.q || undefined,
                role: p.role || undefined,
                include_disabled: p.include_disabled ? 1 : undefined,
                tags: p.tags.length > 0 ? p.tags : undefined,
                tags_mode: p.tags.length > 0 ? p.tags_mode : undefined,
            },
            { preserveState: true, replace: true },
        ),
);

// reka-ui <Select> rejects empty-string item values — proxy '' ↔ the sentinel.
const roleModel = computed({
    get: () => filters.role || ALL_ROLES,
    set: (v: string) => {
        filters.role = v === ALL_ROLES ? '' : v;
        commit();
    },
});

// Search applies live, debounced; filters.q updates immediately so the input
// stays responsive while the server query waits for the pause in typing.
const debouncedCommit = useDebounceFn(() => commit(), 300);
function onSearch(value: string | number): void {
    filters.q = String(value);
    debouncedCommit();
}

onMounted(() => {
    store.hydrate(props.users);
    hydrateTagAttachments(props.users);
    prefs.ensureHydrated(authUser?.preferences ?? null);

    // Restore the user's last filters when arriving at a clean (unfiltered)
    // /users — server-side, so it reloads once with the saved query.
    restoreSaved(
        !props.filters.q &&
            !props.filters.role &&
            !props.filters.include_disabled &&
            (props.filters.tags?.length ?? 0) === 0,
    );

    if (authUser?.org_id) {
        store.subscribe(authUser.org_id);
        tagsStore.subscribe(authUser.org_id);
    }

    // TagPickerPopover needs the full library (sans already-attached)
    // to render its grid. Subsequent renders rely on the realtime
    // store — load once.
    tagsStore.loadLibrary().catch(() => {
        /* surfaced through store */
    });
});

// Re-hydrate on prop changes (Inertia partial reload after filter change).
watch(
    () => props.users,
    (next) => {
        store.hydrate(next);
        hydrateTagAttachments(next);
    },
);

const roles = [
    'Owner',
    'SuperAdmin',
    'Admin',
    'Manager',
    'SelfEdit',
    'SelfView',
    'None',
];

const isSelf = (row: UserRow): boolean => row.id === authUser?.id;

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingUser = ref<UserRow | null>(null);

const openCreate = () => {
    modalMode.value = 'create';
    editingUser.value = null;
    modalOpen.value = true;
};

const openEdit = (row: UserRow) => {
    modalMode.value = 'edit';
    editingUser.value = row;
    modalOpen.value = true;
};

const toggleStatus = (row: UserRow) => {
    if (row.status === 'active') {
        store.disable(row.id);
    } else {
        store.enable(row.id);
    }
};

const remove = (row: UserRow) => {
    if (
        !window.confirm(
            `Delete ${row.name}? This soft-deletes the user — they can no longer log in.`,
        )
    ) {
        return;
    }

    store.destroy(row.id);
};
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Users"
                description="Manage members of your organization."
            />
            <Button v-if="can_create" @click="openCreate"> + Add user </Button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Input
                :model-value="filters.q"
                placeholder="Search name, email, title, dept, location, emp #"
                class="max-w-xs"
                @update:model-value="onSearch"
            />
            <Select v-model="roleModel">
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="All roles" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL_ROLES">All roles</SelectItem>
                    <SelectItem v-for="r in roles" :key="r" :value="r">
                        {{ r }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox
                    :model-value="filters.include_disabled"
                    @update:model-value="
                        (v) => {
                            filters.include_disabled = Boolean(v);
                            commit();
                        }
                    "
                />
                Show disabled
            </label>
            <TableColumnsMenu
                class="ml-auto"
                :columns="columnDefs"
                @toggle="toggleColumn"
                @move="moveColumn"
            />
        </div>

        <div
            class="overflow-x-auto rounded-md border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <SortableHeader
                            v-for="col in visibleColumns"
                            :key="col.key"
                            :label="col.label"
                            :sort-key="col.key"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <th class="px-4 py-2 text-right font-medium">
                            Actions
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            <div class="inline-flex items-center gap-2">
                                <span>Tags</span>
                                <TagFilter
                                    v-model:tag-ids="filters.tags"
                                    v-model:mode="filters.tags_mode"
                                    @update:tag-ids="commit"
                                    @update:mode="commit"
                                />
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="u in sorted" :key="u.id">
                        <td
                            v-for="col in visibleColumns"
                            :key="col.key"
                            class="px-4 py-2"
                        >
                            <template v-if="col.key === 'name'">
                                <Link
                                    :href="userShow(u.id)"
                                    class="font-medium text-primary hover:underline"
                                >
                                    {{ u.name }}
                                </Link>
                                <span
                                    v-if="isSelf(u)"
                                    class="ml-1 text-xs text-muted-foreground"
                                >
                                    (you)
                                </span>
                            </template>
                            <template v-else-if="col.key === 'role'">
                                <Badge variant="secondary" v-if="u.role">
                                    {{ u.role }}
                                </Badge>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </template>
                            <template v-else-if="col.key === 'status'">
                                <Badge
                                    :variant="
                                        u.status === 'active'
                                            ? 'default'
                                            : 'destructive'
                                    "
                                >
                                    {{ u.status }}
                                </Badge>
                            </template>
                            <template v-else>{{ cellText(u, col.key) }}</template>
                        </td>
                        <td class="space-x-3 px-4 py-2 text-right">
                            <button
                                v-if="u.can_edit"
                                type="button"
                                class="text-xs text-primary hover:underline"
                                @click="openEdit(u)"
                            >
                                Edit
                            </button>
                            <button
                                v-if="u.can_disable && !isSelf(u)"
                                type="button"
                                class="text-xs text-amber-700 hover:underline dark:text-amber-400"
                                @click="toggleStatus(u)"
                            >
                                {{
                                    u.status === 'active' ? 'Disable' : 'Enable'
                                }}
                            </button>
                            <button
                                v-if="u.can_delete && !isSelf(u)"
                                type="button"
                                class="text-xs text-destructive hover:underline"
                                @click="remove(u)"
                            >
                                Delete
                            </button>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <TagsListCell
                                morphable-type="App\Models\User"
                                :morphable-id="u.id"
                            />
                        </td>
                    </tr>
                    <tr v-if="sorted.length === 0">
                        <td
                            :colspan="visibleColumns.length + 2"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No users match the current filters.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UserFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editingUser"
        />
    </div>
</template>
