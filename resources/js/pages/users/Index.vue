<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
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
import { useServerTable } from '@/composables/useServerTable';
import { useTableFilter } from '@/composables/useTableFilter';
import { ASSIGNABLE_ROLES } from '@/lib/userRoles';
import MergeUsersModal from '@/pages/users/Partials/MergeUsersModal.vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import UserRowActions from '@/pages/users/Partials/UserRowActions.vue';
import UsersBulkAddGrid from '@/pages/users/Partials/UsersBulkAddGrid.vue';
import { show as userShow } from '@/routes/users';
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

const error = ref<string | null>(null);
const initialLoading = ref(true);

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

// Gate the filter `apply` until the initial fetch has run, so restoring the
// session filter on mount only seeds params (the one initial fetch picks them
// up) instead of firing a second request. `applyFilters` is a hoisted function
// so it can be passed to useTableFilter before `table` is declared below.
let ready = false;
function applyFilters(): void {
    if (ready) {
        table.reload();
    }
}

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
    applyFilters,
);

// Server-paged table. The fetcher merges the live filter state onto the
// page/sort params, so the whole query — search, role, disabled, tags, sort,
// paging — runs in the DB.
const table = useServerTable<UserRow>(
    (params) =>
        store.fetchPage({
            ...params,
            q: filters.q,
            role: filters.role,
            include_disabled: filters.include_disabled,
            tags: filters.tags,
            tags_mode: filters.tags_mode,
        }),
    { perPage: 25, sort: 'name', dir: 'asc' },
);

// Hydrate the tags store for each fetched page so TagsListCell renders attached
// pills without a per-row fetch.
watch(
    () => table.rows.value,
    (rows) => hydrateTagAttachments(rows),
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

function onSort(columnKey: string): void {
    // Column keys map 1:1 to the server sort keys.
    table.setSort(columnKey);
}

// Realtime: a user mutation (local or peer broadcast) re-pulls the current page.
watch(
    () => store.revision,
    () => table.refetchSoon(),
);

onMounted(async () => {
    prefs.ensureHydrated(authUser?.preferences ?? null);

    // Seed params from the session filter (when the page arrived without filter
    // params in the URL); `apply` no-ops until `ready`, so this doesn't fetch.
    restore(
        !props.filters.q &&
            !props.filters.role &&
            !props.filters.include_disabled &&
            (props.filters.tags?.length ?? 0) === 0,
    );
    ready = true;

    if (authUser?.org_id) {
        store.subscribe(authUser.org_id);
        tagsStore.subscribe(authUser.org_id);
    }

    tagsStore.loadLibrary().catch(() => {
        /* surfaced through store */
    });

    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
    }
});

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

// BULK USER ADD — spreadsheet-style grid above the table. The table only holds
// the current page now, so the email-dedup hint + supervisor picker source the
// full org roster from the picker cache (loaded when the grid opens).
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
        void store.loadPicker();
    }
};

// After a bulk add, the submitting tab's own UserRegistered broadcasts are
// self-echo-filtered, so re-pull the current page to surface any new rows that
// match the active filter/sort.
const onBulkDone = () => {
    table.refetchSoon();
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
                    Merge duplicate users
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

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                view-id="users"
                :default-columns="USERS_COLUMNS"
                :rows="table.rows.value"
                :sort-key="table.sort.value"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
                @sort="onSort"
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

        <UserFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editingUser"
        />

        <MergeUsersModal v-if="can_create" v-model:open="mergeOpen" />
    </div>
</template>
