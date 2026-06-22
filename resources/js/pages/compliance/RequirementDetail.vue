<script setup lang="ts">
/* Per-requirement compliance detail (click a requirement on the By-Requirement
 * tab). One row per training a user owes; managers can create a class from the
 * selection (presetting the trainings involved) or add a single row's user to
 * an existing class for that training. */
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import { showPage } from '@/routes/classes';
import type { ServerTableQuery } from '@/composables/useServerTable';
import { useComplianceStore } from '@/stores/compliance';
import type { ComplianceUserRow } from '@/stores/compliance';

const props = defineProps<{
    requirement: { id: string; name: string };
    counts: Record<string, number>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Compliance', href: '/compliance' }] },
});

const store = useComplianceStore();
const page = usePage();
const canManage = computed(() => {
    const u = page.props.auth.user as {
        isOwner?: boolean;
        isSuperAdmin?: boolean;
        isAdmin?: boolean;
        isManager?: boolean;
    } | null;
    return Boolean(u?.isOwner || u?.isSuperAdmin || u?.isAdmin || u?.isManager);
});

const STATUS_CHIPS = [
    { key: 'overdue', label: 'Overdue' },
    { key: 'due_soon', label: 'Due soon' },
    { key: 'not_started', label: 'Not started' },
    { key: 'current', label: 'Current' },
    { key: 'as_needed', label: 'As-needed' },
];

const fetcher = (params: ServerTableQuery) =>
    store.requirementUsers(props.requirement.id, params);

// Per-row "add to existing class" — each row is one training, so it's
// unambiguous (unlike a bulk add across the requirement's many trainings).
const addOpen = ref(false);
const rowToAdd = ref<ComplianceUserRow | null>(null);
function openAdd(row: ComplianceUserRow): void {
    rowToAdd.value = row;
    addOpen.value = true;
}
function onAdded(classId: string): void {
    router.visit(showPage(classId));
}
</script>

<template>
    <ComplianceDetail
        :title="requirement.name"
        description="Compliance for this requirement. Filter by status or tag; click a name for that user's record."
        view-id="compliance-requirement-detail"
        :counts="counts"
        :status-chips="STATUS_CHIPS"
        :fetcher="fetcher"
        show-training
        :selectable="canManage"
    >
        <template #toolbar="{ selectedUserIds, selectedTrainingIds }">
            <ClassActionsBar
                v-if="canManage"
                :selected-user-ids="selectedUserIds"
                :create-training-ids="selectedTrainingIds"
                :preset-name="requirement.name"
            />
        </template>

        <template #row-actions="{ row }">
            <Button
                v-if="canManage"
                type="button"
                size="sm"
                variant="outline"
                :data-testid="`row-add-to-class-${row.user_id}-${row.training_id}`"
                @click="openAdd(row)"
            >
                Add to class
            </Button>
        </template>
    </ComplianceDetail>

    <AddToClassModal
        v-if="canManage"
        v-model:open="addOpen"
        :training-id="rowToAdd?.training_id ?? ''"
        :training-name="rowToAdd?.training ?? ''"
        :user-ids="rowToAdd ? [rowToAdd.user_id] : []"
        @added="onAdded"
    />
</template>
