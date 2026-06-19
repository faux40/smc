<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import {
    blankTrainingForm,
    trainingFormPayload,
    trainingToForm,
} from '@/lib/trainingForm';
import TrainingFields from '@/pages/trainings/Partials/TrainingFields.vue';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';
import type { TrainingRow } from '@/stores/trainings';

const FORM_CTX = 'form:training';

type Mode = 'create' | 'edit';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: TrainingRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const trainings = useTrainingsStore();
const errorStore = useErrorStore();

const form = ref(blankTrainingForm());
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit training' : 'New training'));

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        form.value =
            isEdit.value && props.target
                ? trainingToForm(props.target)
                : blankTrainingForm();
    },
);

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const payload = trainingFormPayload(form.value);

        if (isEdit.value && props.target) {
            await trainings.update(props.target.id, payload);
        } else {
            await trainings.create(payload);
        }

        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save training',
        });
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-5xl">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Template for a training. The three timing flags get
                        copied into rqmt_elements when this training is added to
                        a Requirement.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <TrainingFields v-model="form" :context="FORM_CTX" />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        {{ submitting ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
