<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import TrainingFormModal from '@/pages/trainings/Partials/TrainingFormModal.vue';
import { page as trainingsPage } from '@/routes/trainings';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Trainings', href: trainingsPage() }],
    },
});

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
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<TrainingRow | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await store.load();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (row: TrainingRow) => {
    modalMode.value = 'edit';
    editing.value = row;
    modalOpen.value = true;
};

const remove = async (row: TrainingRow) => {
    if (!window.confirm(`Delete training "${row.name}"?`)) {
        return;
    }

    error.value = null;

    try {
        await store.destroy(row.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
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

        <AsyncState
            :loading="loading"
            :error="error"
            :empty="store.library.length === 0"
        >
            <template #empty>
                <div
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No trainings yet.
                    <span v-if="canCreate"
                        >Click "+ New training" to add one.</span
                    >
                </div>
            </template>

            <div class="overflow-hidden rounded-md border border-border">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">
                                Name
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Timing
                            </th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in store.library" :key="row.id">
                            <td class="px-4 py-2">
                                <div class="font-medium">
                                    {{ row.name }}
                                    <span
                                        v-if="row.nickname"
                                        class="font-normal text-muted-foreground"
                                    >
                                        ({{ row.nickname }})
                                    </span>
                                </div>
                                <div
                                    v-if="row.description"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ row.description }}
                                </div>
                            </td>
                            <td class="px-4 py-2">
                                <Badge variant="secondary">{{
                                    timingSummary(row)
                                }}</Badge>
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

        <TrainingFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
        />
    </div>
</template>
