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
            expiryStatus === 'never_completed'
                ? 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-800 dark:bg-red-950 dark:text-red-400'
                : expiryStatus === 'expired'
                  ? 'border-neutral-300 bg-neutral-100 text-neutral-400 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-500'
                  : 'border-border bg-background text-foreground hover:bg-muted',
        ]"
        :title="row.name + (row.expires_at ? ` — expires ${row.expires_at}` : '')"
        @click="$emit('click')"
    >
        <span
            class="truncate"
            :class="{ 'line-through': expiryStatus === 'expired' }"
        >{{ row.name }}</span>
        <span
            v-if="expiryStatus === 'never_completed'"
            class="text-[10px] leading-tight text-red-500 dark:text-red-400"
        >Not started</span>
        <span
            v-else-if="row.expires_at"
            class="text-[10px] leading-tight"
            :class="
                expiryStatus === 'expired'
                    ? 'text-neutral-400 dark:text-neutral-500'
                    : expiryStatus === 'expiring'
                      ? 'text-amber-600 dark:text-amber-400'
                      : 'text-muted-foreground'
            "
        >{{ row.expires_at }}</span>
    </button>
</template>
