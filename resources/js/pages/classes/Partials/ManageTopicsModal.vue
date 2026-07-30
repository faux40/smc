<script setup lang="ts">
import { computed, ref } from 'vue';
import DualListShuttle from '@/components/DualListShuttle.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { optionalNumber } from '@/lib/forms';
import { useClassesStore } from '@/stores/classes';
import { useTrainingsStore } from '@/stores/trainings';

const props = defineProps<{ open: boolean; classId: string }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();
const trainings = useTrainingsStore();

const detail = computed(() => store.detail[props.classId] ?? null);
const canEdit = computed(
    () =>
        detail.value?.can_edit === true && detail.value?.status === 'scheduled',
);

const actionError = ref<string | null>(null);
async function run(fn: () => Promise<unknown>): Promise<void> {
    actionError.value = null;

    try {
        await fn();
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    }
}

const columns = [
    { key: 'name', label: 'Topic' },
    { key: 'freq', label: 'Frequency' },
    { key: 'std_hours', label: 'std hrs', numeric: true },
];

const trainingDefaults = computed(
    () => new Map(trainings.library.map((t) => [t.id, t.default_hours])),
);

const freqLabel = (t: {
    std_freq_name: string | null;
    as_needed: boolean;
    initial_only: boolean;
}) =>
    t.std_freq_name ??
    (t.as_needed ? 'as-needed' : t.initial_only ? 'initial' : '—');

interface TopicItem {
    id: string;
    name: string;
    freq: string;
    std_hours: string | null;
    hours: string | null;
}

const assigned = computed<TopicItem[]>(() =>
    (detail.value?.trainings ?? []).map((t) => ({
        id: t.id,
        name: t.training_name,
        freq: freqLabel(t),
        std_hours: t.training_id
            ? (trainingDefaults.value.get(t.training_id) ?? null)
            : null,
        hours: t.hours,
    })),
);

const available = computed<TopicItem[]>(() => {
    const taken = new Set(
        (detail.value?.trainings ?? []).map((t) => t.training_id),
    );

    return trainings.library
        .filter((t) => !taken.has(t.id))
        .map((t) => ({
            id: t.id,
            name: t.name,
            freq: freqLabel(t),
            std_hours: t.default_hours,
            hours: t.default_hours,
        }));
});

const assign = (item: { id: string }) =>
    run(() =>
        store.attachTraining(props.classId, {
            training_id: item.id,
            hours: null,
        }),
    );
const unassign = (item: { id: string }) =>
    run(() => store.detachTraining(props.classId, item.id));
const changeHours = (item: { id: string }, value: string) =>
    run(() =>
        store.updateTrainingHours(
            props.classId,
            item.id,
            optionalNumber(value),
        ),
    );
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Training topics</DialogTitle>
                <DialogDescription>
                    Add or remove topics and set per-class hours. Changes save
                    immediately.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <DualListShuttle
                :assigned="assigned"
                :available="available"
                :columns="columns"
                assigned-title="Assigned topics"
                available-title="Available topics"
                search-placeholder="Search topics…"
                always-expanded
                :disabled="!canEdit"
                @assign="assign"
                @unassign="unassign"
            >
                <template #extra-header>Hours</template>
                <template #extra="{ item }">
                    <Input
                        type="number"
                        step="0.25"
                        min="0"
                        :model-value="item.hours ?? ''"
                        :disabled="!canEdit"
                        class="h-7 w-20 text-xs"
                        @change="
                            changeHours(
                                item,
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </template>
            </DualListShuttle>

            <DialogFooter>
                <Button type="button" @click="emit('update:open', false)">
                    Done
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
