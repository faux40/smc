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
import AsyncState from '@/components/AsyncState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
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

const filteredRows = computed(() => {
    return store.rows.filter((a) => {
        if (userFilter.value && a.user_id !== userFilter.value) {
            return false;
        }

        if (
            requirementFilter.value &&
            a.requirement_id !== requirementFilter.value
        ) {
            return false;
        }

        return true;
    });
});

const sortedUsers = computed(() =>
    [...userPicker.value].sort((a, b) =>
        (a.l_name ?? '').localeCompare(b.l_name ?? ''),
    ),
);
const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
);

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (row: AssignmentRow) => {
    modalMode.value = 'edit';
    editing.value = row;
    modalOpen.value = true;
};

const remove = async (row: AssignmentRow) => {
    const userName = (() => {
        const u = userById(row.user_id);

        return u ? [u.f_name, u.l_name].filter(Boolean).join(' ') : 'user';
    })();

    if (
        !window.confirm(`Soft-delete assignment "${row.name}" for ${userName}?`)
    ) {
        return;
    }

    error.value = null;

    try {
        await store.destroy(row.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
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
                {{ filteredRows.length }} of {{ store.rows.length }}
            </span>
        </div>

        <AsyncState
            :loading="loading"
            :error="error"
            :empty="filteredRows.length === 0"
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
                            <th class="px-4 py-2 text-left font-medium">
                                User
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Requirement
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Timing
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Start
                            </th>
                            <th class="px-4 py-2 text-left font-medium">End</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in filteredRows" :key="row.id">
                            <td class="px-4 py-2">
                                <template v-if="userById(row.user_id)">
                                    {{
                                        [
                                            userById(row.user_id)?.f_name,
                                            userById(row.user_id)?.l_name,
                                        ]
                                            .filter(Boolean)
                                            .join(' ')
                                    }}
                                    <div
                                        v-if="userById(row.user_id)?.email"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ userById(row.user_id)?.email }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2">
                                {{
                                    requirementById(row.requirement_id)?.name ??
                                    row.name
                                }}
                            </td>
                            <td class="px-4 py-2 text-xs">
                                <span v-if="row.initial_only"
                                    >Initial-only</span
                                >
                                <span v-else-if="row.repeating">Repeating</span>
                                <span v-else-if="row.as_needed">As-needed</span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-2 text-xs">
                                {{ row.start_date ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs">
                                {{ row.end_date ?? '—' }}
                            </td>
                            <td class="space-x-3 px-4 py-2 text-right text-xs">
                                <button
                                    v-if="row.can_edit"
                                    type="button"
                                    class="text-primary hover:underline"
                                    @click="openEdit(row)"
                                >
                                    Edit
                                </button>
                                <button
                                    v-if="row.can_delete"
                                    type="button"
                                    class="text-destructive hover:underline"
                                    @click="remove(row)"
                                >
                                    Delete
                                </button>
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
        />
    </div>
</template>
