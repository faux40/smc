<script setup lang="ts">
/* Per-requirement compliance detail (click a requirement on the By-Requirement
 * tab). One row per training a user owes; managers can create a class from the
 * selection (presetting the trainings involved) or add a single row's user to
 * an existing class for that training. */
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import ReportGroupingModal from '@/components/ReportGroupingModal.vue';
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import type { ServerTableQuery } from '@/composables/useServerTable';
import { useComplianceStore } from '@/stores/compliance';
import type { ComplianceUserRow } from '@/stores/compliance';
import { useRemind } from '@/composables/useRemind';

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
function onAdded(): void {
    toast.success(`Added ${rowToAdd.value?.name ?? 'user'} to the class.`);
    rowToAdd.value = null;
}

// F12 — export this requirement's compliance-status snapshot (PDF + CSV),
// scoped to the requirement so the document shows just its people. Reuses the
// same grouping modal/href pattern as the Reports page.
const exportOpen = ref(false);
const GROUP_OPTIONS = [
    { key: 'department', label: 'Department' },
    { key: 'location', label: 'Location' },
    { key: 'status', label: 'Status' },
    { key: 'training', label: 'Training' },
];
const exportBaseHref = computed(
    () =>
        `/api/reports/compliance-status/export?requirement_id=${encodeURIComponent(props.requirement.id)}`,
);

// F10 — per-row / bulk "Remind" nudges.
const { remindOne, remindMany } = useRemind();

function taIdsOf(rows: ComplianceUserRow[]): string[] {
    return rows
        .map((r) => r.training_assignment_id)
        .filter((id): id is string => Boolean(id));
}

async function remindSelected(
    rows: ComplianceUserRow[],
    clear: () => void,
    reload: () => void,
): Promise<void> {
    const ok = await remindMany(taIdsOf(rows));
    if (ok) {
        clear();
        reload();
    }
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
        <template #header-actions>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="open-requirement-export"
                @click="exportOpen = true"
            >
                Export…
            </Button>
        </template>

        <template
            #toolbar="{ selectedRows, selectedUserIds, selectedTrainingIds, clear, reload }"
        >
            <template v-if="canManage">
                <ClassActionsBar
                    :selected-user-ids="selectedUserIds"
                    :create-training-ids="selectedTrainingIds"
                    :preset-name="requirement.name"
                    @done="clear"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="selectedRows.length === 0"
                    data-testid="remind-selected"
                    @click="remindSelected(selectedRows, clear, reload)"
                >
                    Remind selected ({{ selectedRows.length }})
                </Button>
            </template>
        </template>

        <template #row-actions="{ row }">
            <div v-if="canManage" class="flex items-center justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :data-testid="`row-add-to-class-${row.user_id}-${row.training_id}`"
                    @click="openAdd(row)"
                >
                    Add to class
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :data-testid="`row-remind-${row.user_id}-${row.training_id}`"
                    @click="remindOne(row.training_assignment_id ?? '')"
                >
                    Remind
                </Button>
            </div>
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

    <ReportGroupingModal
        v-model:open="exportOpen"
        :base-href="exportBaseHref"
        :options="GROUP_OPTIONS"
    />
</template>
