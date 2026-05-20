<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<RequirementRow | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await store.load();
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (row: RequirementRow) => {
    modalMode.value = 'edit';
    editing.value = row;
    modalOpen.value = true;
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
    } catch (e) {
        error.value = (e as Error).message;
    }
};
</script>

<template>
    <Head title="Requirements" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Requirements"
                description="Named groups of rqmt_elements. Use the detail page to add Trainings / future modules."
            />
            <Button v-if="canCreate" @click="openCreate"
                >+ New requirement</Button
            >
        </div>

        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div
            v-if="store.library.length === 0"
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No requirements yet.
            <span v-if="canCreate">Click "+ New requirement" to add one.</span>
        </div>

        <div v-else class="overflow-hidden rounded-md border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Name</th>
                        <th class="px-4 py-2 text-left font-medium">
                            Elements
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in store.library" :key="row.id">
                        <td class="px-4 py-2">
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
                        </td>
                        <td class="px-4 py-2">
                            <Badge variant="secondary">{{
                                row.elements_count
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

        <RequirementFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
        />
    </div>
</template>
