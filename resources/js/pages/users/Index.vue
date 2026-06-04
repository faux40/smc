<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import TagFilter from '@/components/TagFilter.vue';
import type { TagFilterMode } from '@/components/TagFilter.vue';
import TagsListCell from '@/components/TagsListCell.vue';
import { useTableSort } from '@/composables/useTableSort';
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
    },
    { key: 'name', dir: 'asc' },
);

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
const search = ref(props.filters.q);
const roleFilter = ref(props.filters.role || ALL_ROLES);
const includeDisabled = ref(props.filters.include_disabled);
const tagFilter = ref<string[]>(props.filters.tags ?? []);
const tagFilterMode = ref<TagFilterMode>(props.filters.tags_mode ?? 'and');

onMounted(() => {
    store.hydrate(props.users);
    hydrateTagAttachments(props.users);

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

const applyFilters = () => {
    router.get(
        index().url,
        {
            q: search.value || undefined,
            role:
                roleFilter.value && roleFilter.value !== ALL_ROLES
                    ? roleFilter.value
                    : undefined,
            include_disabled: includeDisabled.value ? 1 : undefined,
            tags: tagFilter.value.length > 0 ? tagFilter.value : undefined,
            // Only send mode when there are tags — keeps the URL clean
            // when the filter is empty.
            tags_mode:
                tagFilter.value.length > 0 ? tagFilterMode.value : undefined,
        },
        { preserveState: true, replace: true },
    );
};

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
                v-model="search"
                placeholder="Search by name or email"
                class="max-w-xs"
                @keyup.enter="applyFilters"
            />
            <Select v-model="roleFilter" @update:model-value="applyFilters">
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
                    :model-value="includeDisabled"
                    @update:model-value="
                        (v) => {
                            includeDisabled = Boolean(v);
                            applyFilters();
                        }
                    "
                />
                Show disabled
            </label>
            <Button variant="outline" @click="applyFilters">Apply</Button>
        </div>

        <div
            class="overflow-x-auto rounded-md border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <SortableHeader
                            label="Name"
                            sort-key="name"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Email"
                            sort-key="email"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Role"
                            sort-key="role"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Status"
                            sort-key="status"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Job title"
                            sort-key="job_title"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Employee #"
                            sort-key="employee_number"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Department"
                            sort-key="department"
                            :active-key="sortKey"
                            :dir="sortDir"
                            @sort="toggleSort"
                        />
                        <SortableHeader
                            label="Location"
                            sort-key="location"
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
                                    v-model:tag-ids="tagFilter"
                                    v-model:mode="tagFilterMode"
                                    @update:tag-ids="applyFilters"
                                    @update:mode="applyFilters"
                                />
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="u in sorted" :key="u.id">
                        <td class="px-4 py-2">
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
                        </td>
                        <td class="px-4 py-2">{{ u.email ?? '—' }}</td>
                        <td class="px-4 py-2">
                            <Badge variant="secondary" v-if="u.role">
                                {{ u.role }}
                            </Badge>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    u.status === 'active'
                                        ? 'default'
                                        : 'destructive'
                                "
                            >
                                {{ u.status }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">{{ u.job_title ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ u.employee_number ?? '—' }}
                        </td>
                        <td class="px-4 py-2">{{ u.department ?? '—' }}</td>
                        <td class="px-4 py-2">{{ u.location ?? '—' }}</td>
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
                            colspan="10"
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
