<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerTable } from '@/composables/useServerTable';
import { realtimeTabId } from '@/echo';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import { page as completionsPage } from '@/routes/completions';
import { useCompletionsStore } from '@/stores/completions';
import type { CompletionRow } from '@/stores/completions';
import { usePreferencesStore } from '@/stores/preferences';
import type { PrefsBlob } from '@/stores/preferences';
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

// Only DB-backed columns can be server-sorted. User / training-name / source /
// credits aren't (they'd need joins / are computed) — left non-sortable for now.
const COMPLETIONS_COLUMNS = [
    { key: 'user', label: 'User' },
    { key: 'module', label: 'Training' },
    { key: 'date', label: 'Date', sortable: true },
    { key: 'expires', label: 'Expires', sortable: true },
    { key: 'hours', label: 'Hours', sortable: true },
    { key: 'source', label: 'Source' },
    { key: 'credits', label: 'Credits' },
];

// Column key ⇄ server sort column.
const COLUMN_SORT: Record<string, string> = {
    date: 'completion_date',
    expires: 'expire_date',
    hours: 'hours',
};
const SORT_COLUMN: Record<string, string> = {
    completion_date: 'date',
    expire_date: 'expires',
    hours: 'hours',
};

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
            preferences?: PrefsBlob | null;
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
const search = ref('');

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<CompletionRow | null>(null);
const error = ref<string | null>(null);
const initialLoading = ref(true);

// Server-paged table — the store relay owns the fetch; userFilter is folded in
// here (the composable's own params are page/sort/dir/q).
const table = useServerTable<CompletionRow>(
    (params) =>
        store.fetchPage({ ...params, user_id: userFilter.value || undefined }),
    { perPage: 25, sort: 'completion_date', dir: 'desc' },
);

const activeColumnKey = computed(() =>
    table.sort.value ? (SORT_COLUMN[table.sort.value] ?? null) : null,
);

function onSort(columnKey: string): void {
    const serverKey = COLUMN_SORT[columnKey];

    if (serverKey) {
        table.setSort(serverKey);
    }
}

const userById = (id: string) => userPicker.value.find((u) => u.id === id);
const trainingById = (id: string) => trainings.library.find((t) => t.id === id);

// Server-resolved (M1); the lookup is only a fallback for stale rows.
const moduleLabel = (row: CompletionRow): string => {
    if (row.training_name) {
        return row.training_name;
    }

    if (row.module_type === 'App\\Models\\Training') {
        return trainingById(row.module_id)?.name ?? 'Training';
    }

    return row.module_type;
};

// ── Filter persistence ────────────────────────────────────────────────────────
function restoreSavedFilters(): void {
    const saved = prefs.view('completions').filters as
        | { user_id?: string }
        | undefined;

    if (saved?.user_id !== undefined) {
        userFilter.value = saved.user_id;
    }
}

watch(userFilter, () => {
    prefs.update('completions', { filters: { user_id: userFilter.value } });
    table.reload();
});

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

// Realtime: a completion broadcast just re-pulls the current page.
watch(
    () => store.revision,
    () => table.refetchSoon(),
);

onMounted(async () => {
    prefs.ensureHydrated(authUser.value?.preferences ?? null);
    restoreSavedFilters();

    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([table.fetchPage(), trainings.load(), loadUsers()]);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
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
    const u = userById(row.user_id);
    const userName = u
        ? [u.f_name, u.l_name].filter(Boolean).join(' ')
        : 'user';

    if (!window.confirm(`Soft-delete this completion for ${userName}?`)) {
        return;
    }

    error.value = null;

    try {
        await store.destroy(row.id);
        table.refetchSoon();
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

        <AsyncState
            :loading="initialLoading"
            :error="error"
            :empty="table.total.value === 0"
        >
            <template #empty>
                <div
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No completions match the current filter.
                    <span v-if="canCreate">
                        Click "+ New completion" to record one.</span
                    >
                </div>
            </template>

            <DataTable
                view-id="completions"
                :default-columns="COMPLETIONS_COLUMNS"
                :rows="table.rows.value"
                :sort-key="activeColumnKey"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
                @sort="onSort"
            >
                <template #filters>
                    <div class="grid gap-1">
                        <Label for="filter_user" class="text-xs"
                            >Filter by user</Label
                        >
                        <select
                            id="filter_user"
                            v-model="userFilter"
                            class="rounded border border-input bg-background px-2 py-1 text-sm"
                        >
                            <option value="">All users</option>
                            <option
                                v-for="u in sortedUsers"
                                :key="u.id"
                                :value="u.id"
                            >
                                {{
                                    [u.f_name, u.l_name]
                                        .filter(Boolean)
                                        .join(' ') ||
                                    u.email ||
                                    u.id
                                }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1">
                        <Label for="filter_q" class="text-xs">Search</Label>
                        <Input
                            id="filter_q"
                            :model-value="search"
                            placeholder="Cert # or notes"
                            class="h-8 w-48"
                            @update:model-value="onSearch"
                        />
                    </div>
                </template>

                <template #lead-header>
                    <th
                        class="w-10 px-2 py-2 text-right font-medium text-muted-foreground"
                    >
                        #
                    </th>
                </template>
                <template #lead-cells="{ index }">
                    <td
                        class="w-10 px-2 py-2 text-right text-xs text-muted-foreground"
                    >
                        {{
                            (table.page.value - 1) * table.perPage.value +
                            index +
                            1
                        }}
                    </td>
                </template>

                <template #col-user="{ row }">
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
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #col-module="{ row }">{{
                    moduleLabel(row)
                }}</template>

                <template #col-date="{ row }">
                    <span class="text-xs">{{
                        row.completion_date ?? '—'
                    }}</span>
                </template>

                <template #col-expires="{ row }">
                    <span class="text-xs">{{ row.expire_date ?? '—' }}</span>
                </template>

                <template #col-hours="{ row }">
                    <span class="text-xs">{{ row.hours ?? '—' }}</span>
                </template>

                <template #col-source="{ row }">
                    <a
                        v-if="row.class_id"
                        :href="`/classes/${row.class_id}`"
                        class="text-xs text-primary hover:underline"
                    >
                        {{ row.class_name ?? 'Class' }}
                    </a>
                    <span v-else class="text-xs text-muted-foreground"
                        >Manual</span
                    >
                </template>

                <template #col-credits="{ row }">
                    <Badge variant="secondary">
                        {{ row.effective_element_ids.length }} element(s)
                    </Badge>
                </template>

                <template #trail-header>
                    <th class="px-4 py-2"></th>
                </template>

                <template #trail-cells="{ row }">
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
                </template>
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

        <CompletionFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
        />
    </div>
</template>
