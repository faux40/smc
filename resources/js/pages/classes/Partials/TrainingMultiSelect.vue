<script setup lang="ts">
import { Label } from '@/components/ui/label';
import type { TrainingRow } from '@/stores/trainings';

/**
 * Multi-select checkbox list of existing trainings, used by the new-class
 * form to snapshot a starting set of trainings onto the class. `modelValue`
 * is the array of selected training ids (v-model).
 */
const props = defineProps<{
    trainings: TrainingRow[];
    modelValue: string[];
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: string[]): void;
}>();

function toggle(id: string, checked: boolean): void {
    const next = checked
        ? [...props.modelValue, id]
        : props.modelValue.filter((x) => x !== id);
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="grid gap-2">
        <Label>Trainings</Label>
        <div
            v-if="trainings.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No trainings on file yet — create some first, or attach them later
            on the class page.
        </div>
        <ul
            v-else
            class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-border p-2"
        >
            <li v-for="t in trainings" :key="t.id">
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        class="size-4 rounded border-input"
                        :value="t.id"
                        :checked="modelValue.includes(t.id)"
                        @change="
                            toggle(
                                t.id,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    <span>{{ t.name }}</span>
                    <span v-if="t.default_hours" class="ml-auto text-xs text-muted-foreground">
                        {{ t.default_hours }}h
                    </span>
                </label>
            </li>
        </ul>
    </div>
</template>
