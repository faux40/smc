<script setup lang="ts">
/*
 * Per-training compliance detail (click a training on the Compliance "By
 * training" tab). The training's status tallies + assigned users, plus the
 * class-actions bar (assemble a class / add to an existing one) and a PDF export.
 * Thin wrapper over the shared ComplianceDetail — one training, so it offers the
 * single-training add-to-existing path.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
import ClassActionsBar from '@/pages/classes/Partials/ClassActionsBar.vue';
import type { ServerTableQuery } from '@/composables/useServerTable';
import { useComplianceStore } from '@/stores/compliance';

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

        <template #toolbar="{ selectedUserIds, clear }">
            <ClassActionsBar
                v-if="canManage"
                :selected-user-ids="selectedUserIds"
                :create-training-ids="[training.id]"
                :preset-name="training.name"
                :add-training-id="training.id"
                :add-training-name="training.name"
                @done="clear"
            />
        </template>
    </ComplianceDetail>
</template>
