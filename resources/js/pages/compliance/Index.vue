<script setup lang="ts">
/*
 * Compliance page — org-wide roll-ups pivoted by training or requirement.
 * Tabs keep each dimension in its own table (and its own query). Manager+
 * (route-gated). A "Not-required" tab + per-row drill-down land in a later
 * expansion.
 */
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import ComplianceRollupTable from '@/pages/compliance/Partials/ComplianceRollupTable.vue';
import { useComplianceStore } from '@/stores/compliance';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Compliance', href: '/compliance' }],
    },
});

const store = useComplianceStore();

const TABS = [
    { key: 'training', label: 'By training' },
    { key: 'requirement', label: 'By requirement' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

const tab = ref<TabKey>('training');
const isActive = (key: TabKey) => tab.value === key;

const activeConfig = computed(() =>
    tab.value === 'training'
        ? {
              viewId: 'compliance-training',
              nameLabel: 'Training',
              searchPlaceholder: 'Search trainings…',
              fetcher: store.byTraining,
          }
        : {
              viewId: 'compliance-requirement',
              nameLabel: 'Requirement',
              searchPlaceholder: 'Search requirements…',
              fetcher: store.byRequirement,
          },
);
</script>

<template>
    <Head title="Compliance" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Compliance"
            description="Org-wide compliance at a glance. Pivot by training or requirement; search and sort any column."
        />

        <div class="flex gap-1 border-b border-border">
            <Button
                v-for="t in TABS"
                :key="t.key"
                type="button"
                variant="ghost"
                size="sm"
                :data-testid="`compliance-tab-${t.key}`"
                :class="[
                    'rounded-none border-b-2 -mb-px',
                    isActive(t.key)
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground',
                ]"
                @click="tab = t.key"
            >
                {{ t.label }}
            </Button>
        </div>

        <!-- Keyed so switching tabs remounts the table with the new fetcher. -->
        <ComplianceRollupTable
            :key="activeConfig.viewId"
            :view-id="activeConfig.viewId"
            :name-label="activeConfig.nameLabel"
            :search-placeholder="activeConfig.searchPlaceholder"
            :fetcher="activeConfig.fetcher"
        />
    </div>
</template>
