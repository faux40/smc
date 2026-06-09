<script setup lang="ts">
import type { RequirementAssignmentRow } from '@/stores/requirementAssignments';

const props = defineProps<{
    row: RequirementAssignmentRow;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    remove: [];
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border border-border px-2.5 py-0.5 text-xs font-medium text-foreground"
    >
        {{ props.row.requirement_name }}
        <button
            v-if="canDelete"
            type="button"
            class="-mr-0.5 ml-0.5 rounded-full p-0.5 opacity-60 hover:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="`Remove ${props.row.requirement_name}`"
            @click="emit('remove')"
        >
            &times;
        </button>
    </span>
</template>
