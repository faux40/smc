<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
import RequirementFormModal from '@/pages/requirements/Partials/RequirementFormModal.vue';
import {
    page as requirementsPage,
    show as requirementsShow,
} from '@/routes/requirements';
import { useRequirementsStore } from '@/stores/requirements';
import type { RequirementRow } from '@/stores/requirements';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Requirements', href: requirementsPage() }],
    },
});

const REQUIREMENTS_COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'elements', label: 'Elements', sortable: true },
];

// Column key ⇄ server sort column.
const COLUMN_SORT: Record<string, string> = {
    name: 'name',
    elements: 'elements_count',
};
const SORT_COLUMN: Record<string, string> = {
    name: 'name',
    elements_count: 'elements',
};

const store = useRequirementsStore();
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
        } | null,
);
const canCreate = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const error = ref<string | null>(null);
const initialLoading = ref(true);
const modalOpen = ref(false);
const search = ref('');

// Server-paged table — the store relay owns the fetch.
const table = useServerTable<RequirementRow>(
    (params) => store.fetchPage(params),
    { perPage: 25, sort: 'name', dir: 'asc' },
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

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

// Realtime: a requirement broadcast just re-pulls the current page.
watch(
    () => store.revision,
    () => table.refetchSoon(),
);

const openCreate = () => {
    modalOpen.value = true;
};

// Land on the new requirement's detail page — that's where elements (and
// further name/description edits) are managed.
const onCreated = (row: RequirementRow) => {
    router.visit(requirementsShow(row.id));
};

const remove = async (row: RequirementRow) => {
    if (
        !window.confirm(
            `Delete requirement "${row.name}"? (Soft delete — elements + their history stay until the row is hard-purged later.)`,
        )
    ) {
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

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await table.fetchPage();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        initialLoading.value = false;
    }
});
</script>

<template>
    <Head title="Requirements" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Requirements"
                description="Named groups of trainings. Open one to edit its details and manage its elements."
            />
            <Button v-if="canCreate" @click="openCreate"
                >+ New requirement</Button
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
                    No requirements match the current filter.
                    <span v-if="canCreate"
                        >Click "+ New requirement" to add one.</span
                    >
                </div>
            </template>

            <DataTable
                view-id="requirements"
                :default-columns="REQUIREMENTS_COLUMNS"
                :rows="table.rows.value"
                :sort-key="activeColumnKey"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
                @sort="onSort"
            >
                <template #filters>
                    <div class="grid gap-1">
                        <Label for="filter_q" class="text-xs">Search</Label>
                        <Input
                            id="filter_q"
                            :model-value="search"
                            placeholder="Name or description"
                            class="h-8 w-56"
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

                <template #col-name="{ row }">
                    <Link
                        :href="requirementsShow(row.id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ row.name }}
                    </Link>
                    <div
                        v-if="row.description"
                        class="text-xs text-muted-foreground"
                    >
                        {{ row.description }}
                    </div>
                </template>

                <template #col-elements="{ row }">
                    <Badge variant="secondary">{{ row.elements_count }}</Badge>
                </template>

                <template #trail-header>
                    <th class="px-4 py-2"></th>
                </template>

                <template #trail-cells="{ row }">
                    <td class="space-x-3 px-4 py-2 text-right text-xs">
                        <Link
                            :href="requirementsShow(row.id)"
                            class="text-primary hover:underline"
                        >
                            Open
                        </Link>
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

        <RequirementFormModal
            v-model:open="modalOpen"
            mode="create"
            @created="onCreated"
        />
    </div>
</template>
