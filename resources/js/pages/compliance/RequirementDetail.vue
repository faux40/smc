<script setup lang="ts">
/* Per-requirement compliance detail (click a requirement on the By-Requirement
 * tab). The requirement's people + their status. */
import ComplianceDetail from '@/pages/compliance/Partials/ComplianceDetail.vue';
import type { ServerTableQuery } from '@/composables/useServerTable';
import { useComplianceStore } from '@/stores/compliance';

const props = defineProps<{
    requirement: { id: string; name: string };
    counts: Record<string, number>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Compliance', href: '/compliance' }] },
});

const store = useComplianceStore();

const STATUS_CHIPS = [
    { key: 'overdue', label: 'Overdue' },
    { key: 'due_soon', label: 'Due soon' },
    { key: 'not_started', label: 'Not started' },
    { key: 'current', label: 'Current' },
    { key: 'as_needed', label: 'As-needed' },
];

const fetcher = (params: ServerTableQuery) =>
    store.requirementUsers(props.requirement.id, params);
</script>

<template>
    <ComplianceDetail
        :title="requirement.name"
        description="Compliance for this requirement. Filter by status or tag; click a name for that user's record."
        view-id="compliance-requirement-detail"
        :counts="counts"
        :status-chips="STATUS_CHIPS"
        :fetcher="fetcher"
    />
</template>
