<script setup lang="ts">
/*
 * Per-training compliance detail (click a training on the Compliance "By
 * training" tab). The training's status tallies + assigned users, plus the
 * class-actions bar (assemble a class / add to an existing one) and a PDF export.
 * Thin wrapper over the shared ComplianceDetail — one training, so it offers the
 * single-training add-to-existing path.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import CompletionFormModal from '@/pages/completions/Partials/CompletionFormModal.vue';
import type { ServerTableQuery } from '@/composables/useServerTable';
import type { CompletionBulkResult } from '@/stores/completions';
import { useComplianceStore } from '@/stores/compliance';
import type { ComplianceUserRow } from '@/stores/compliance';
import { useRemind } from '@/composables/useRemind';

const props = defineProps<{
    training: { id: string; name: string };
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
    store.trainingUsers(props.training.id, params);

// F8 — "record completion for the selected people" without the class workflow.
// The modal opens in multi-user mode (this one training × the selected users);
// on success we toast the created/skipped tallies, clear the selection, and
// refetch the table so statuses re-render.
const recordOpen = ref(false);
const recordUserIds = ref<string[]>([]);
let onRecorded: (() => void) | null = null;

function openRecord(userIds: string[], clear: () => void, reload: () => void): void {
    recordUserIds.value = userIds;
    onRecorded = () => {
        clear();
        reload();
    };
    recordOpen.value = true;
}

function onRecordSaved(result?: CompletionBulkResult): void {
    if (result) {
        const { created_count, skipped_count } = result;
        const noun = created_count === 1 ? 'completion' : 'completions';
        toast.success(
            skipped_count > 0
                ? `Recorded ${created_count} ${noun} · ${skipped_count} skipped.`
                : `Recorded ${created_count} ${noun}.`,
        );
    }
    onRecorded?.();
    onRecorded = null;
}

// F10 — "Remind": nudge one person, or everyone in the selection. Overdue
// reminders CC the supervisor server-side; the toast reports the tally.
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
        :title="training.name"
        description="Compliance for this training. Filter by status, then select users to assemble a class."
        view-id="compliance-training-detail"
        :counts="counts"
        :status-chips="STATUS_CHIPS"
        :fetcher="fetcher"
        :selectable="canManage"
    >
        <template #header-actions>
            <Button as-child variant="outline" size="sm">
                <a
                    :href="`/api/reports/training/${training.id}/record`"
                    target="_blank"
                    rel="noopener"
                    data-testid="export-training-record"
                >
                    Export report (PDF)
                </a>
            </Button>
        </template>

        <template #toolbar="{ selectedRows, selectedUserIds, clear, reload }">
            <template v-if="canManage">
                <ClassActionsBar
                    :selected-user-ids="selectedUserIds"
                    :create-training-ids="[training.id]"
                    :preset-name="training.name"
                    :add-training-id="training.id"
                    :add-training-name="training.name"
                    @done="clear"
                />
                <Button
                    type="button"
                    variant="outline"
                    :disabled="selectedUserIds.length === 0"
                    data-testid="record-completion"
                    @click="openRecord(selectedUserIds, clear, reload)"
                >
                    Record completion for selected ({{ selectedUserIds.length }})
                </Button>
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
            <Button
                v-if="canManage"
                type="button"
                size="sm"
                variant="ghost"
                :data-testid="`row-remind-${row.user_id}`"
                @click="remindOne(row.training_assignment_id ?? '')"
            >
                Remind
            </Button>
        </template>
    </ComplianceDetail>

    <CompletionFormModal
        v-model:open="recordOpen"
        mode="create"
        :initial-training-id="training.id"
        :user-ids="recordUserIds"
        @saved="onRecordSaved"
    />
</template>
