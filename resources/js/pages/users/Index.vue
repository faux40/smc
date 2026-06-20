<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
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
import { useTableFilter } from '@/composables/useTableFilter';
import { useTableSort } from '@/composables/useTableSort';
import { ASSIGNABLE_ROLES } from '@/lib/userRoles';
import MergeUsersModal from '@/pages/users/Partials/MergeUsersModal.vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import UserRowActions from '@/pages/users/Partials/UserRowActions.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import { index, show as userShow } from '@/routes/users';
import { usePreferencesStore } from '@/stores/preferences';
import type { PrefsBlob } from '@/stores/preferences';
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

const USERS_COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'role', label: 'Role', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'job_title', label: 'Job title', sortable: true },
    { key: 'employee_number', label: 'Employee #', sortable: true },
    { key: 'department', label: 'Department', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'supervisor', label: 'Supervisor', sortable: true },
];

const store = useUsersStore();
const tagsStore = useTagsStore();
const page = usePage();
const authUser = page.props.auth.user as AuthUser;
const prefs = usePreferencesStore();

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

function hydrateTagAttachments(rows: UserRow[]): void {
    for (const u of rows) {
        tagsStore.setAttached(
            { type: 'App\\Models\\User', id: u.id },
            u.tag_ids ?? [],
        );
    }
}

const ALL_ROLES = '__all';

type UserFilters = {
    q: string;
    role: string;
    include_disabled: boolean;
    tags: string[];
    tags_mode: TagFilterMode;
};

const BLANK_FILTERS: UserFilters = {
    q: '',
    role: '',
    include_disabled: false,
    tags: [],
    tags_mode: 'and',
};

const {
    params: filters,
    commit,
    restore,
    clear,
} = useTableFilter<UserFilters>(
    'users',
    {
        q: props.filters.q,
        role: props.filters.role,
        include_disabled: props.filters.include_disabled,
        tags: props.filters.tags ?? [],
        tags_mode: props.filters.tags_mode ?? 'and',
    },
    BLANK_FILTERS,
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

// Drives the visible "Clear" control — shown only when a filter is active.
const hasActiveFilters = computed(
    () =>
        filters.q !== '' ||
        filters.role !== '' ||
        filters.include_disabled ||
        filters.tags.length > 0,
);

const roleModel = computed({
    get: () => filters.role || ALL_ROLES,
    set: (v: string) => {
        filters.role = v === ALL_ROLES ? '' : v;
        commit();
    },
});

const debouncedCommit = useDebounceFn(() => commit(), 300);
function onSearch(value: string | number): void {
    filters.q = String(value);
    debouncedCommit();
}

onMounted(() => {
    store.hydrate(props.users);
    hydrateTagAttachments(props.users);
    prefs.ensureHydrated(authUser?.preferences ?? null);

    // Re-apply the session filter on every load: when the page arrived without
    // filter params in the URL (e.g. via the breadcrumb / nav), restore the
    // session's last filter so the list stays filtered until explicitly cleared.
    restore(
        !props.filters.q &&
            !props.filters.role &&
            !props.filters.include_disabled &&
            (props.filters.tags?.length ?? 0) === 0,
    );

    if (authUser?.org_id) {
        store.subscribe(authUser.org_id);
        tagsStore.subscribe(authUser.org_id);
    }

    tagsStore.loadLibrary().catch(() => {
        /* surfaced through store */
    });
});

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

// BULK USER ADD — spreadsheet-style grid above the table.
const showBulk = ref(false);
const bulkRoles = [...ASSIGNABLE_ROLES];
const existingEmails = computed(() =>
    store.users
        .map((u) => u.email)
        .filter((e): e is string => typeof e === 'string' && e !== ''),
);
const bulkSupervisors = computed(() =>
    store.users.map((u) => ({ id: u.id, name: u.name })),
);
const toggleBulk = () => {
    showBulk.value = !showBulk.value;

    if (showBulk.value) {
        void store.loadFieldOptions();
    }
};

// After a bulk add, the submitting tab's own UserRegistered broadcasts are
// self-echo-filtered, so reload the server-filtered users prop to surface the
// new rows (only those matching the current filter, like single-add does).
const onBulkDone = () => {
    router.reload({ only: ['users'] });
};

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingUser = ref<UserRow | null>(null);
const mergeOpen = ref(false);

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
            <div v-if="can_create" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    data-testid="combine-users-btn"
                    @click="mergeOpen = true"
                >
                    Combine users
                </Button>
                <Button variant="outline" @click="toggleBulk">
                    {{ showBulk ? 'Close bulk add' : 'Bulk add' }}
                </Button>
                <Button @click="openCreate"> + Add user </Button>
            </div>
        </div>

        <UsersBulkAddGrid
            v-if="can_create && showBulk"
            :existing-emails="existingEmails"
            :roles="bulkRoles"
            :supervisors="bulkSupervisors"
            :field-options="store.fieldOptions"
            @done="onBulkDone"
            @close="showBulk = false"
        />

        <DataTable
            view-id="users"
            :default-columns="USERS_COLUMNS"
            :rows="sorted"
            :sort-key="sortKey"
            :sort-dir="sortDir"
            :row-key="(row) => row.id"
            @sort="toggleSort"
        >
            <template #filters>
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
                <Button
                    v-if="hasActiveFilters"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    @click="clear"
                >
                    <X class="size-4" />
                    Clear filters
                </Button>
            </template>

            <template #col-name="{ row }">
                <Link
                    :href="userShow(row.id)"
                    class="font-medium text-primary hover:underline"
                >
                    {{ row.sort_name }}
                </Link>
                <span
                    v-if="isSelf(row)"
                    class="ml-1 text-xs text-muted-foreground"
                >
                    (you)
                </span>
            </template>

            <template #col-role="{ row }">
                <Badge variant="secondary" v-if="row.role">{{
                    row.role
                }}</Badge>
                <span v-else class="text-muted-foreground">—</span>
            </template>

            <template #col-status="{ row }">
                <Badge
                    :variant="
                        row.status === 'active' ? 'default' : 'destructive'
                    "
                >
                    {{ row.status }}
                </Badge>
            </template>

            <template #col-supervisor="{ row }">
                {{ row.supervisor_sort_name ?? '—' }}
            </template>

            <template #trail-header>
                <th class="px-4 py-2 text-right font-medium">Actions</th>
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
            </template>

            <template #trail-cells="{ row }">
                <td class="px-4 py-2 text-right">
                    <UserRowActions
                        :row="row"
                        :is-self="isSelf(row)"
                        @edit="openEdit(row)"
                        @toggle-status="toggleStatus(row)"
                        @delete="remove(row)"
                    />
                </td>
                <td class="px-4 py-2 text-right">
                    <TagsListCell
                        morphable-type="App\Models\User"
                        :morphable-id="row.id"
                    />
                </td>
            </template>

            <template #empty>No users match the current filters.</template>
        </DataTable>

        <UserFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editingUser"
        />

        <MergeUsersModal v-if="can_create" v-model:open="mergeOpen" />
    </div>
</template>
