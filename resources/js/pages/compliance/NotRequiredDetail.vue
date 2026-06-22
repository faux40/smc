<script setup lang="ts">
/* Not-required detail (click a training on the Not-Required tab). The EEs who
 * took the training without being required to — Current vs Taken-but-Expired. */
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
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
    />
</template>
