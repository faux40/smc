<script setup lang="ts">
import { computed } from 'vue';
import type { TrainingAssignmentRow } from '@/stores/trainingAssignments';

const props = defineProps<{
    row: TrainingAssignmentRow;
    expiringSoonDays?: number;
}>();

defineEmits<{ (e: 'click'): void }>();

type ExpiryStatus = 'never_completed' | 'expired' | 'expiring' | 'ok';

const threshold = computed(() => props.expiringSoonDays ?? 30);
const today = new Date().toISOString().slice(0, 10);

const expiryStatus = computed<ExpiryStatus>(() => {
    if (!props.row.last_completed_at) return 'never_completed';
    if (!props.row.expires_at) return 'ok';
    if (props.row.expires_at < today) return 'expired';
    const daysOut =
        (new Date(props.row.expires_at).getTime() - new Date(today).getTime()) /
        86_400_000;
    return daysOut <= threshold.value ? 'expiring' : 'ok';
});
</script>

<template>
    <button
        type="button"
        :class="[
            'inline-flex max-w-full flex-col items-start rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
            expiryStatus === 'never_completed' || expiryStatus === 'expired'
                ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-800 dark:bg-red-950 dark:text-red-400'
                : expiryStatus === 'expiring'
                  ? 'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-400'
                  : 'border-green-300 bg-green-50 text-green-700 hover:bg-green-100 dark:border-green-800 dark:bg-green-950 dark:text-green-400',
        ]"
        :title="row.name + (row.expires_at ? ` — expires ${row.expires_at}` : '')"
        @click="$emit('click')"
    >
        <span
            class="truncate"
            :class="{ 'line-through': expiryStatus === 'expired' }"
        >{{ row.name }}</span>
        <span
            v-if="row.expires_at"
            class="text-[10px] leading-tight"
        >{{ row.expires_at }}</span>
    </button>
</template>
