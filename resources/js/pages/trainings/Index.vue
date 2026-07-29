<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
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
import TrainingFormModal from '@/pages/trainings/Partials/TrainingFormModal.vue';
import {
    page as trainingsPage,
    show as trainingShow,
} from '@/routes/trainings';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Trainings', href: trainingsPage() }],
    },
});

const TRAININGS_COLUMNS = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'timing', label: 'Timing', sortable: false },
];

const store = useTrainingsStore();
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

const modalOpen = ref(false);
const error = ref<string | null>(null);
const initialLoading = ref(true);
const search = ref('');

const table = useServerTable<TrainingRow>((params) => store.fetchPage(params), {
    perPage: 25,
    sort: 'name',
    dir: 'asc',
});

function onSearch(value: string | number): void {
    search.value = String(value);
    table.setQuery(search.value);
}

// Realtime: a training mutation (local or peer broadcast) re-pulls the page.
watch(
    () => store.revision,
    () => table.refetchSoon(),
);

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

const openCreate = () => {
    modalOpen.value = true;
};

const timingSummary = (row: TrainingRow): string => {
    const parts: string[] = [];

    if (row.initial_only) {
        parts.push('initial-only');
    }

    if (row.repeating) {
        parts.push(
            row.std_freq_name
                ? `repeating (${row.std_freq_name})`
                : 'repeating',
        );
    }

    if (row.as_needed) {
        parts.push('as-needed');
    }

    return parts.join(' · ');
};
</script>

<template>
    <Head title="Trainings" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Trainings"
                description="Module templates. Timing flags get copied into rqmt_elements when added to a Requirement."
            />
            <Button v-if="canCreate" @click="openCreate">+ New training</Button>
        </div>

        <AsyncState :loading="initialLoading" :error="error">
            <DataTable
                view-id="trainings"
                :default-columns="TRAININGS_COLUMNS"
                :rows="table.rows.value"
                :sort-key="table.sort.value"
                :sort-dir="table.dir.value"
                :row-key="(row) => row.id"
                @sort="table.setSort"
            >
                <template #filters>
                    <div class="grid gap-1">
                        <Label for="filter_q" class="text-xs">Search</Label>
                        <Input
                            id="filter_q"
                            :model-value="search"
                            placeholder="Name, nickname, or description"
                            class="h-8 w-64"
                            @update:model-value="onSearch"
                        />
                    </div>
                </template>

                <template #col-name="{ row }">
                    <Link
                        :href="trainingShow(row.id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ row.name }}
                        <span
                            v-if="row.nickname"
                            class="font-normal text-muted-foreground"
                        >
                            ({{ row.nickname }})
                        </span>
                    </Link>
                    <div
                        v-if="row.description"
                        class="text-xs text-muted-foreground"
                    >
                        {{ row.description }}
                    </div>
                </template>

                <template #col-timing="{ row }">
                    <Badge variant="secondary">{{ timingSummary(row) }}</Badge>
                </template>

                <template #empty
                    >No trainings match the current search.</template
                >
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

        <TrainingFormModal v-model:open="modalOpen" mode="create" />
    </div>
</template>
