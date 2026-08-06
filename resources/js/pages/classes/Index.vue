<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import AttachmentViewer from '@/components/AttachmentViewer.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useGeneratedDoc } from '@/composables/useGeneratedDoc';
import { useServerTable } from '@/composables/useServerTable';
import { useTableView } from '@/composables/useTableView';
import type { ColumnDef } from '@/composables/useTableView';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import { page as classesPage, showPage } from '@/routes/classes';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail, ClassRow } from '@/stores/classes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Classes', href: classesPage() }],
    },
});

// Per-row print. Which document a row can produce is decided by its state:
// a class that hasn't closed has nothing to summarise (the server refuses a
// summary outright), and a blank sign-in sheet for a finished class is no use.
// So one button, two meanings — and the label has to say which.
const {
    open: docOpen,
    active: activeDoc,
    openDoc: openClassDoc,
} = useGeneratedDoc();

const printKindFor = (row: ClassRow) =>
    row.status === 'completed' ? ('summary' as const) : ('sign-in' as const);

const printLabelFor = (row: ClassRow) =>
    row.status === 'completed'
        ? `Print summary for ${row.name}`
        : `Print sign-in sheet for ${row.name}`;

function printRow(row: ClassRow): void {
    const kind = printKindFor(row);

    openClassDoc(
        row.id,
        kind,
        kind === 'summary' ? 'Class summary' : 'Sign-in sheet',
    );
}

const CLASSES_COLUMNS: ColumnDef[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'instructor', label: 'Instructor', sortable: true },
    { key: 'date', label: 'Date', sortable: true },
    { key: 'hours', label: 'Hours', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'trainings', label: 'Trainings', sortable: true },
    { key: 'enrolled', label: 'Enrolled', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
];

// Column key ⇄ server sort column.
const COLUMN_SORT: Record<string, string> = {
    name: 'name',
    instructor: 'instructor',
    date: 'scheduled_date',
    hours: 'total_hours',
    location: 'location',
    trainings: 'class_trainings_count',
    enrolled: 'enrollments_count',
    status: 'status',
};
const SORT_COLUMN: Record<string, string> = {
    name: 'name',
    instructor: 'instructor',
    scheduled_date: 'date',
    total_hours: 'hours',
    location: 'location',
    class_trainings_count: 'trainings',
    enrollments_count: 'enrolled',
    status: 'status',
};

const store = useClassesStore();
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
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);

const error = ref<string | null>(null);
const initialLoading = ref(true);
const modalOpen = ref(false);
const search = ref('');

// Server-paged table — the store relay owns the fetch.
const table = useServerTable<ClassRow>((params) => store.fetchPage(params), {
    perPage: 25,
    sort: 'scheduled_date',
    dir: 'desc',
});

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

/**
 * Export link. DataTable owns the column picker internally, so the page runs a
 * second useTableView against the same view id purely to read what is visible
 * — without it the sheet would print every column regardless of what was
 * hidden on screen. Same mirror the Reports exports use.
 */
const exportView = useTableView('classes', CLASSES_COLUMNS);

const exportHref = computed(() => {
    const params = new URLSearchParams();

    if (search.value) {
        params.set('q', search.value);
    }

    if (table.sort.value) {
        params.set('sort', table.sort.value);
        params.set('dir', table.dir.value);
    }

    for (const col of exportView.visibleColumns.value) {
        params.append('columns[]', col.key);
    }

    return `/api/classes/export?${params.toString()}`;
});

// Realtime: a ClassChanged broadcast just re-pulls the current page.
watch(
    () => store.revision,
    () => table.refetchSoon(),
);

function onSaved(detail: ClassDetail): void {
    router.visit(showPage(detail.id));
}

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
    <Head title="Classes" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Classes"
                description="Schedule a class, attach trainings, and enroll users. Close it out to record completions."
            />
            <div class="flex items-center gap-2">
                <a
                    :href="exportHref"
                    target="_blank"
                    rel="noopener"
                    data-testid="export-classes"
                    class="inline-flex h-9 items-center gap-1.5 rounded-md border border-input px-3 text-sm hover:bg-muted"
                    title="Print this list with the filters, sort and columns shown"
                >
                    <Printer class="h-4 w-4" />
                    Print list
                </a>
                <Button v-if="canManage" @click="modalOpen = true">
                    + New class
                </Button>
            </div>
        </div>

        <AsyncState
            :loading="initialLoading"
            :error="error"
            :empty="table.total.value === 0"
            empty-text="No classes scheduled yet."
        >
            <DataTable
                view-id="classes"
                :default-columns="CLASSES_COLUMNS"
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
                            placeholder="Name, instructor, or location"
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

                <template #trail-header>
                    <th class="w-12 px-2 py-2">
                        <span class="sr-only">Print</span>
                    </th>
                </template>
                <template #trail-cells="{ row }">
                    <td class="w-12 px-2 py-2 text-right">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="h-7 w-7"
                            data-testid="print-class-doc"
                            :aria-label="printLabelFor(row as ClassRow)"
                            :title="printLabelFor(row as ClassRow)"
                            @click="printRow(row as ClassRow)"
                        >
                            <Printer class="h-4 w-4" />
                        </Button>
                    </td>
                </template>

                <template #col-name="{ row }">
                    <Link
                        :href="showPage(row.id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ row.name }}
                    </Link>
                </template>

                <template #col-instructor="{ row }">
                    <span class="text-xs">{{ row.instructor ?? '—' }}</span>
                </template>

                <template #col-date="{ row }">
                    <span class="text-xs">{{ row.scheduled_date ?? '—' }}</span>
                </template>

                <template #col-hours="{ row }">
                    <span class="text-xs">{{ row.total_hours ?? '—' }}</span>
                </template>

                <template #col-location="{ row }">
                    <span class="text-xs">{{ row.location ?? '—' }}</span>
                </template>

                <template #col-trainings="{ row }">{{
                    row.trainings_count
                }}</template>
                <!-- Reference max (never a limit) reads as "7 / 20". -->
                <template #col-enrolled="{ row }">
                    <span
                        :title="
                            row.min_students != null
                                ? `min ${row.min_students}`
                                : undefined
                        "
                    >
                        {{ row.enrollments_count
                        }}<template v-if="row.max_students != null">
                            / {{ row.max_students }}</template
                        >
                    </span>
                </template>

                <template #col-status="{ row }">
                    <Badge
                        :variant="
                            row.status === 'completed' ? 'secondary' : 'default'
                        "
                    >
                        {{ row.status }}
                    </Badge>
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

        <ClassFormModal v-model:open="modalOpen" @saved="onSaved" />

        <!-- Same in-app viewer the class detail page uses: preview, print,
             download, and "save to this class's files" — no second tab. -->
        <AttachmentViewer v-model:open="docOpen" :generated="activeDoc" />
    </div>
</template>
