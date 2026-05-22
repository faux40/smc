<script setup lang="ts">
/*
 * Status pill used by the user detail page (and any future
 * dashboard/due-soon view). String literal lines up with
 * UserComplianceCalculator::STATUS_* on the backend.
 */
import { computed } from 'vue';

export type ComplianceStatus =
    | 'overdue'
    | 'due_soon'
    | 'current'
    | 'never_started'
    | 'inactive';

// Optional count rendered inside the pill (e.g. "Overdue · 3") — used by the
// dashboard list; omitted elsewhere.
const props = defineProps<{ status: ComplianceStatus; count?: number }>();

const presentation = computed(() => {
    switch (props.status) {
        case 'overdue':
            return {
                label: 'Overdue',
                classes:
                    'bg-red-100 text-red-800 ring-red-200 dark:bg-red-900/30 dark:text-red-200 dark:ring-red-800',
            };
        case 'due_soon':
            return {
                label: 'Due soon',
                classes:
                    'bg-amber-100 text-amber-900 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800',
            };
        case 'current':
            return {
                label: 'Current',
                classes:
                    'bg-emerald-100 text-emerald-900 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-800',
            };
        case 'never_started':
            return {
                label: 'Not started',
                classes: 'bg-muted text-muted-foreground ring-border',
            };
        case 'inactive':
            return {
                label: 'Inactive',
                classes: 'bg-muted text-muted-foreground ring-border',
            };
        default:
            // Defensive fallback: backend STATUS_* drift shouldn't crash the
            // template (which reads presentation.classes/.label unconditionally).
            return {
                label: 'Unknown',
                classes: 'bg-muted text-muted-foreground ring-border',
            };
    }
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="presentation.classes"
    >
        {{ presentation.label
        }}<span v-if="count != null" class="ml-1 font-semibold"
            >·&nbsp;{{ count }}</span
        >
    </span>
</template>
