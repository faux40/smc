<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import UserFormModal from '@/pages/users/Partials/UserFormModal.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { index } from '@/routes/users';
import { useUsersStore, type UserRow } from '@/stores/users';

type Filters = {
    q: string;
    role: string;
    include_disabled: boolean;
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
const page = usePage();
const authUser = page.props.auth.user as AuthUser;

const search = ref(props.filters.q);
const roleFilter = ref(props.filters.role);
const includeDisabled = ref(props.filters.include_disabled);

onMounted(() => {
    store.hydrate(props.users);
    if (authUser?.org_id) store.subscribe(authUser.org_id);
});

// Re-hydrate on prop changes (Inertia partial reload after filter change).
watch(
    () => props.users,
    (next) => store.hydrate(next),
);

const applyFilters = () => {
    router.get(
        index().url,
        {
            q: search.value || undefined,
            role: roleFilter.value || undefined,
            include_disabled: includeDisabled.value ? 1 : undefined,
        },
        { preserveState: true, replace: true },
    );
};

const roles = ['Owner', 'SuperAdmin', 'Admin', 'Manager', 'SelfEdit', 'SelfView', 'None'];

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
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Users"
                description="Manage members of your organization."
            />
            <Button v-if="can_create" @click="openCreate">
                + Add user
            </Button>
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
                    <SelectItem value="">All roles</SelectItem>
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
            class="overflow-hidden rounded-md border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Name</th>
                        <th class="px-4 py-2 text-left font-medium">Email</th>
                        <th class="px-4 py-2 text-left font-medium">Role</th>
                        <th class="px-4 py-2 text-left font-medium">Status</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="u in store.users" :key="u.id">
                        <td class="px-4 py-2">
                            {{ u.name }}
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
                                disabled
                                class="text-xs text-muted-foreground/60"
                            >
                                Disable
                            </button>
                            <button
                                v-if="u.can_delete && !isSelf(u)"
                                disabled
                                class="text-xs text-muted-foreground/60"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                    <tr v-if="store.users.length === 0">
                        <td
                            colspan="5"
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
