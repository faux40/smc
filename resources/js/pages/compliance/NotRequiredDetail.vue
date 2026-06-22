<script setup lang="ts">
/* Not-required detail (click a training on the Not-Required tab). The EEs who
 * took the training without being required to — Current vs Taken-but-Expired.
 * Single training, so managers can add the selection to an existing class
 * (re-cert the expired) or create one. */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
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
    { key: 'current', label: 'Current' },
    { key: 'expired', label: 'Taken but Expired' },
];

const fetcher = (params: ServerTableQuery) =>
    store.notRequiredUsers(props.training.id, params);
</script>

<template>
    <ComplianceDetail
        :title="training.name"
        description="People who took this training without being required to."
        view-id="compliance-not-required-detail"
        :counts="counts"
        :status-chips="STATUS_CHIPS"
        :fetcher="fetcher"
        :badge-status-map="{ overdue: 'expired' }"
        :selectable="canManage"
    >
        <template #toolbar="{ selectedUserIds }">
            <ClassActionsBar
                v-if="canManage"
                :selected-user-ids="selectedUserIds"
                :create-training-ids="[training.id]"
                :preset-name="training.name"
                :add-training-id="training.id"
                :add-training-name="training.name"
            />
        </template>
    </ComplianceDetail>
</template>
