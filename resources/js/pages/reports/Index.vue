<script setup lang="ts">
/*
 * Reports — two views:
 *   • Completions — every recorded completion (proves what happened).
 *   • Compliance status — the audit document: every employee × required
 *     training with its CURRENT status + due date (proves where we stand),
 *     including never-started people.
 * Each view is a self-contained child that owns its own table/filters/export;
 * this shell just switches between them.
 */
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import CompletionsReport from '@/pages/reports/Partials/CompletionsReport.vue';
import ComplianceStatusReport from '@/pages/reports/Partials/ComplianceStatusReport.vue';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reports', href: '/reports' }],
    },
});

const TABS = [
    { key: 'completions', label: 'Completions' },
    { key: 'compliance', label: 'Compliance status' },
] as const;
type TabKey = (typeof TABS)[number]['key'];

const activeTab = ref<TabKey>('completions');
</script>

<template>
    <Head title="Reports" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Reports"
            description="Completion history and the current compliance-status snapshot — filter, then export to PDF or CSV."
        />

        <div class="flex gap-1 border-b border-border" role="tablist">
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.key"
                :data-testid="`reports-tab-${tab.key}`"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition-colors"
                :class="
                    activeTab === tab.key
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <CompletionsReport v-if="activeTab === 'completions'" />
        <ComplianceStatusReport v-else />
    </div>
</template>
