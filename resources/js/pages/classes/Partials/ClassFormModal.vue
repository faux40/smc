<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ClassFieldset from '@/components/ClassFieldset.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClassForm } from '@/composables/useClassForm';
import TrainingMultiSelect from '@/pages/classes/Partials/TrainingMultiSelect.vue';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';

const FORM_CTX = 'form:class';

const props = defineProps<{
    open: boolean;
    // Optional seed (e.g. "assemble a class" from the compliance detail page):
    // pre-check these trainings and default the class name.
    presetTrainingIds?: string[];
    presetName?: string;
    // Duplicate mode (Actions → Duplicate on the class detail page): seed the
    // whole form from this class. The date is deliberately left blank — a copy
    // is a new session — and the roster can optionally come along.
    copyFrom?: ClassDetail | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'saved', detail: ClassDetail): void;
}>();

const store = useClassesStore();
const trainings = useTrainingsStore();
const errorStore = useErrorStore();
const { form, setFrom, validate, payload } = useClassForm(FORM_CTX);
const submitting = ref(false);

// Create-only: which existing trainings to snapshot onto the new class.
const selectedTrainingIds = ref<string[]>([]);

// Duplicate mode: bring the source roster along (defaults on).
const includeStudents = ref(true);

const sourceUserIds = computed(() => [
    ...new Set((props.copyFrom?.enrollments ?? []).map((e) => e.user_id)),
]);

// Topics snapshot fresh from the current training templates, so only topics
// whose training still exists (in the active library) can carry over.
const copyableTrainingIds = computed(() => {
    const library = new Set(trainings.library.map((t) => t.id));

    return [
        ...new Set(
            (props.copyFrom?.trainings ?? [])
                .map((t) => t.training_id)
                .filter((id): id is string => id !== null && library.has(id)),
        ),
    ];
});

const uncopyableTopicNames = computed(() => {
    const library = new Set(trainings.library.map((t) => t.id));

    return (props.copyFrom?.trainings ?? [])
        .filter((t) => t.training_id === null || !library.has(t.training_id))
        .map((t) => t.training_name);
});

/** The clearable provenance line, above the source's own notes. */
function copiedFromNote(source: ClassDetail): string {
    const when = source.scheduled_date ? ` (${source.scheduled_date})` : '';
    const marker = `Copied from "${source.name}"${when}.`;

    return source.notes ? `${marker}\n\n${source.notes}` : marker;
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);

        if (props.copyFrom) {
            setFrom(props.copyFrom);
            form.value.scheduled_date = '';
            form.value.notes = copiedFromNote(props.copyFrom);
            includeStudents.value = true;
            // Pre-check once the library is in — copyable ids depend on it.
            selectedTrainingIds.value = [];
            await trainings.load();
            selectedTrainingIds.value = copyableTrainingIds.value;

            return;
        }

        setFrom(null);
        selectedTrainingIds.value = props.presetTrainingIds
            ? [...props.presetTrainingIds]
            : [];

        if (props.presetName) {
            form.value.name = props.presetName;
        }

        void trainings.load();
    },
);

async function submit(): Promise<void> {
    errorStore.clear(FORM_CTX);

    if (!validate()) {
        return;
    }

    submitting.value = true;

    try {
        const detail = await store.create({
            ...payload(),
            training_ids: selectedTrainingIds.value,
            ...(props.copyFrom &&
            includeStudents.value &&
            sourceUserIds.value.length > 0
                ? { user_ids: sourceUserIds.value }
                : {}),
        });
        emit('saved', detail);
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save the class.',
        });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="flex max-h-[90vh] flex-col sm:max-w-4xl">
            <form
                @submit.prevent="submit"
                novalidate
                class="flex min-h-0 flex-1 flex-col gap-4"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ copyFrom ? 'Duplicate class' : 'New class' }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="copyFrom">
                            A new scheduled class pre-filled from “{{
                                copyFrom.name
                            }}”. Pick a date, adjust anything you like, then
                            create it.
                        </template>
                        <template v-else>
                            A scheduled class. Enroll users and close it out
                            later from the detail page to record credit.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="grid min-h-0 flex-1 grid-cols-2 gap-6">
                    <!-- Left: class details -->
                    <div class="min-h-0 overflow-y-auto pr-1">
                        <ClassFieldset v-model="form" :context="FORM_CTX" />
                    </div>

                    <!-- Right: training picker — fills the same height as the left column -->
                    <div class="flex min-h-0 flex-col gap-2">
                        <TrainingMultiSelect
                            v-model="selectedTrainingIds"
                            :trainings="trainings.library"
                        />
                        <p
                            v-if="copyFrom && uncopyableTopicNames.length > 0"
                            class="rounded border border-dashed border-border p-2 text-xs text-muted-foreground"
                        >
                            Can't be copied (training no longer exists):
                            {{ uncopyableTopicNames.join(', ') }}
                        </p>
                        <label
                            v-if="copyFrom && sourceUserIds.length > 0"
                            class="flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <input
                                v-model="includeStudents"
                                type="checkbox"
                                class="size-4 rounded border-input"
                                data-testid="copy-include-students"
                            />
                            Include the {{ sourceUserIds.length }} enrolled
                            student{{ sourceUserIds.length === 1 ? '' : 's' }}
                        </label>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        Create
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
