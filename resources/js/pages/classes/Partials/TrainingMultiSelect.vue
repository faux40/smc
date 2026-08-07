<script setup lang="ts">
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TrainingRow } from '@/stores/trainings';

const props = defineProps<{
    trainings: TrainingRow[];
    modelValue: string[];
    /** Heading over the list; the class modal's default stays "Trainings". */
    label?: string;
    /** Copy for an empty library; defaults to the class-modal wording. */
    emptyText?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', v: string[]): void;
}>();

const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
        return props.trainings;
    }

    return props.trainings.filter((t) => t.name.toLowerCase().includes(q));
});

function toggle(id: string, checked: boolean): void {
    const next = checked
        ? [...props.modelValue, id]
        : props.modelValue.filter((x) => x !== id);
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="flex h-full flex-col gap-2">
        <div class="flex items-center justify-between">
            <Label>{{ label ?? 'Trainings' }}</Label>
            <span
                v-if="modelValue.length > 0"
                class="text-xs text-muted-foreground"
            >
                {{ modelValue.length }} selected
            </span>
        </div>

        <div
            v-if="trainings.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            {{
                emptyText ??
                'No trainings on file yet — create some first, or attach' +
                    ' them later on the class page.'
            }}
        </div>

        <template v-else>
            <Input
                v-model="search"
                type="text"
                placeholder="Filter trainings…"
                class="h-8"
            />
            <ul
                class="min-h-0 flex-1 space-y-1 overflow-y-auto rounded-md border border-border p-2"
            >
                <li
                    v-if="filtered.length === 0"
                    class="px-1 py-2 text-xs text-muted-foreground"
                >
                    No trainings match "{{ search }}".
                </li>
                <li v-for="t in filtered" :key="t.id">
                    <label
                        class="flex cursor-pointer items-center gap-2 text-sm"
                    >
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
                        <span class="flex-1">{{ t.name }}</span>
                        <span
                            v-if="t.default_hours"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t.default_hours }}h
                        </span>
                    </label>
                </li>
            </ul>
        </template>
    </div>
</template>
