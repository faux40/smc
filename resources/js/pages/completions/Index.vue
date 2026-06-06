<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import Heading from '@/components/Heading.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import TableColumnsMenu from '@/components/TableColumnsMenu.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useColumnDrag } from '@/composables/useColumnDrag';
import { useTableSort } from '@/composables/useTableSort';
import { useTableView } from '@/composables/useTableView';
import { realtimeTabId } from '@/echo';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import { page as completionsPage } from '@/routes/completions';
import { useCompletionsStore } from '@/stores/completions';
import type { CompletionRow } from '@/stores/completions';
import { usePreferencesStore } from '@/stores/preferences';
import { useTrainingsStore } from '@/stores/trainings';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Completions', href: completionsPage() }],
    },
});

interface UserPickerRow {
    id: string;
    f_name: string;
    l_name: string;
    email: string | null;
}

const store = useCompletionsStore();
const trainings = useTrainingsStore();
const prefs = usePreferencesStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
            preferences?: Record<string, unknown>;
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

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<CompletionRow | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

// ── Column control ────────────────────────────────────────────────────────────
const {
    columns: columnDefs,
    visibleColumns,
    toggle: toggleColumn,
    reorder: reorderColumn,
} = useTableView('completions', [
    { key: 'user', label: 'User', sortable: true },
    { key: 'module', label: 'Module', sortable: true },
    { key: 'date', label: 'Date', sortable: true },
    { key: 'expires', label: 'Expires', sortable: true },
    { key: 'credits', label: 'Credits', sortable: true },
]);
const { dragAttrs } = useColumnDrag(columnDefs, reorderColumn);

// ── Filtering + sorting ───────────────────────────────────────────────────────
const filteredRows = computed(() =>
    store.rows.filter((c) => !userFilter.value || c.user_id === userFilter.value),
);

const userById = (id: string) => userPicker.value.find((u) => u.id === id);
const trainingById = (id: string) => trainings.library.find((t) => t.id === id);

const moduleLabel = (row: CompletionRow): string => {
    if (row.module_type === 'App\\Models\\Training') {
        return trainingById(row.module_id)?.name ?? 'Training';
    }

    return row.module_type;
};

const { sortKey, sortDir, toggleSort, sorted } = useTableSort<CompletionRow>(
    filteredRows,
    {
        user: (r) => userById(r.user_id)?.l_name ?? '',
        module: (r) => moduleLabel(r),
        date: (r) => r.completion_date ?? '',
        expires: (r) => r.expire_date ?? '',
        credits: (r) => r.rqmt_element_ids.length,
    },
    { key: 'user', dir: 'asc' },
);

// ── Filter persistence ────────────────────────────────────────────────────────
function snapshotFilters() {
    return { user_id: userFilter.value };
}

function restoreSavedFilters(): void {
    const saved = prefs.view('completions').filters as
        | Partial<ReturnType<typeof snapshotFilters>>
        | undefined;
    if (!saved) return;
    if (saved.user_id !== undefined) userFilter.value = saved.user_id;
}

watch(userFilter, () =>
    prefs.update('completions', { filters: snapshotFilters() }),
);

onMounted(async () => {
    prefs.ensureHydrated(authUser.value?.preferences ?? null);
    restoreSavedFilters();

    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([store.loadFor({}), trainings.load(), loadUsers()]);
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

const sortedUsers = computed(() =>
    [...userPicker.value].sort((a, b) =>
        (a.l_name ?? '').localeCompare(b.l_name ?? ''),
    ),
);

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (row: CompletionRow) => {
    modalMode.value = 'edit';
    editing.value = row;
    modalOpen.value = true;
};

const remove = async (row: CompletionRow) => {
    const userName = (() => {
        const u = userById(row.user_id);

        return u ? [u.f_name, u.l_name].filter(Boolean).join(' ') : 'user';
    })();

    if (!window.confirm(`Soft-delete this completion for ${userName}?`)) {
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
    <Head title="Completions" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Completions"
                description="Record that a user satisfied one or more rqmt_elements on a date. One completion can credit several Requirements at once."
            />
            <Button v-if="canCreate" @click="openCreate"
                >+ New completion</Button
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
            <span class="text-xs text-muted-foreground">
                {{ sorted.length }} of {{ store.rows.length }}
            </span>
            <TableColumnsMenu
                class="ml-auto"
                :columns="columnDefs"
                @toggle="toggleColumn"
            />
        </div>

        <AsyncState
            :loading="loading"
            :error="error"
            :empty="sorted.length === 0"
        >
            <template #empty>
                <div
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No completions match the current filter.
                    <span v-if="canCreate && store.rows.length === 0">
                        Click "+ New completion" to record one.
                    </span>
                </div>
            </template>

            <div class="overflow-hidden rounded-md border border-border">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <SortableHeader
                                v-for="col in visibleColumns"
                                :key="col.key"
                                v-bind="dragAttrs(col.key)"
                                :label="col.label"
                                :sort-key="col.key"
                                :active-key="sortKey"
                                :dir="sortDir"
                                @sort="toggleSort"
                            />
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in sorted" :key="row.id">
                            <td
                                v-for="col in visibleColumns"
                                :key="col.key"
                                class="px-4 py-2"
                            >
                                <template v-if="col.key === 'user'">
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
                                            v-if="
                                                userById(row.user_id)?.email
                                            "
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ userById(row.user_id)?.email }}
                                        </div>
                                    </template>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                </template>

                                <template v-else-if="col.key === 'module'">
                                    {{ moduleLabel(row) }}
                                </template>

                                <template v-else-if="col.key === 'date'">
                                    <span class="text-xs">{{
                                        row.completion_date ?? '—'
                                    }}</span>
                                </template>

                                <template v-else-if="col.key === 'expires'">
                                    <span class="text-xs">{{
                                        row.expire_date ?? '—'
                                    }}</span>
                                </template>

                                <template v-else-if="col.key === 'credits'">
                                    <Badge variant="secondary"
                                        >{{
                                            row.rqmt_element_ids.length
                                        }}
                                        element(s)</Badge
                                    >
                                </template>
                            </td>
                            <td
                                class="space-x-3 px-4 py-2 text-right text-xs"
                            >
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

        <CompletionFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
        />
    </div>
</template>
