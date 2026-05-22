<script setup lang="ts">
import { ref, watch } from 'vue';
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

const props = defineProps<{ open: boolean }>();

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

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        setFrom(null);
        selectedTrainingIds.value = [];
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
        <DialogContent class="sm:max-w-2xl">
            <form @submit.prevent="submit" novalidate class="space-y-4">
                <DialogHeader>
                    <DialogTitle>New class</DialogTitle>
                    <DialogDescription>
                        A scheduled class. Enroll users and close it out later
                        from the detail page to record credit.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <ClassFieldset v-model="form" :context="FORM_CTX" />

                <TrainingMultiSelect
                    v-model="selectedTrainingIds"
                    :trainings="trainings.library"
                />

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
