<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRequirementsStore } from '@/stores/requirements';
import { useTrainingsStore } from '@/stores/trainings';

defineProps<{ modelValue: string; disabled?: boolean }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>();

const trainings = useTrainingsStore();
const requirements = useRequirementsStore();

onMounted(async () => {
    await Promise.all([trainings.load(), requirements.load()]);
});

const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
);
const sortedTrainings = computed(() =>
    [...trainings.library].sort((a, b) => a.name.localeCompare(b.name)),
);
</script>

<template>
    <select
        :value="modelValue"
        :disabled="disabled"
        data-testid="item-select"
        class="h-9 w-full rounded border border-input bg-background px-3 text-sm"
        @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
        <option value="" disabled>Pick a requirement or training…</option>
        <optgroup label="Requirements">
            <option
                v-for="r in sortedRequirements"
                :key="r.id"
                :value="`requirement:${r.id}`"
            >
                {{ r.name }}
            </option>
        </optgroup>
        <optgroup label="Trainings">
            <option
                v-for="t in sortedTrainings"
                :key="t.id"
                :value="`training:${t.id}`"
            >
                {{ t.name }}
            </option>
        </optgroup>
    </select>
</template>
