<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import RqmtElementFormModal from '@/pages/requirements/Partials/RqmtElementFormModal.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useRqmtElementsStore, type RqmtElementRow } from '@/stores/rqmtElements';
import { useTrainingsStore } from '@/stores/trainings';
import { page as requirementsPage } from '@/routes/requirements';

const props = defineProps<{
    requirement: {
        id: string;
        name: string;
        description: string | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Requirements', href: requirementsPage() },
        ],
    },
});

const store = useRqmtElementsStore();
const trainings = useTrainingsStore();
const page = usePage();
const authUser = computed(
    () => page.props.auth.user as {
        org_id?: string;
        isOwner?: boolean;
        isSuperAdmin?: boolean;
        isAdmin?: boolean;
    } | null,
);
const canManage = computed(
    () => Boolean(authUser.value?.isOwner || authUser.value?.isSuperAdmin || authUser.value?.isAdmin),
);

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<RqmtElementRow | null>(null);
const error = ref<string | null>(null);

const elements = computed(() => store.listFor(props.requirement.id));

onMounted(async () => {
    if (authUser.value?.org_id) store.subscribe(authUser.value.org_id);
    try {
        await Promise.all([store.loadFor(props.requirement.id), trainings.load()]);
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (row: RqmtElementRow) => {
    modalMode.value = 'edit';
    editing.value = row;
    modalOpen.value = true;
};

const remove = async (row: RqmtElementRow) => {
    if (!window.confirm(`Delete element "${row.name}"?`)) return;
    error.value = null;
    try {
        await store.destroy(row.id, props.requirement.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
};

const moduleLabel = (row: RqmtElementRow): string => {
    // Show "Training: <name>" — look up name from useTrainingsStore.
    if (row.module_type.endsWith('Training')) {
        const t = trainings.library.find((x) => x.id === row.module_id);
        return t ? `Training: ${t.name}` : `Training: ${row.module_id.slice(0, 8)}…`;
    }
    return row.module_type;
};

const timingSummary = (row: RqmtElementRow): string => {
    const parts: string[] = [];
    if (row.initial_only) parts.push('initial-only');
    if (row.repeating) parts.push('repeating');
    if (row.as_needed) parts.push('as-needed');
    return parts.join(' · ');
};
</script>

<template>
    <Head :title="`Requirement: ${props.requirement.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                :title="props.requirement.name"
                :description="props.requirement.description ?? 'Elements bind modules to this requirement.'"
            />
            <Button v-if="canManage" @click="openCreate">+ Add element</Button>
        </div>

        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div
            v-if="elements.length === 0"
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No elements yet.
            <span v-if="canManage">Click "+ Add element" to bind a Training.</span>
        </div>

        <div v-else class="overflow-hidden rounded-md border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Name</th>
                        <th class="px-4 py-2 text-left font-medium">Module</th>
                        <th class="px-4 py-2 text-left font-medium">Timing</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in elements" :key="row.id">
                        <td class="px-4 py-2">
                            <div class="font-medium">{{ row.name }}</div>
                            <div
                                v-if="row.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ row.description }}
                            </div>
                        </td>
                        <td class="px-4 py-2 text-xs">{{ moduleLabel(row) }}</td>
                        <td class="px-4 py-2">
                            <Badge variant="secondary">{{ timingSummary(row) }}</Badge>
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

        <RqmtElementFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :requirement-id="props.requirement.id"
            :target="editing"
        />
    </div>
</template>
