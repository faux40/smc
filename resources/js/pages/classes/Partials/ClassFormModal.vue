<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
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
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { useClassesStore } from '@/stores/classes';
import type { ClassDetail } from '@/stores/classes';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:class';

const props = defineProps<{
    open: boolean;
    mode: 'create' | 'edit';
    target?: ClassDetail | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'saved', detail: ClassDetail): void;
}>();

const store = useClassesStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit class' : 'New class'));

const form = reactive({
    name: '',
    scheduled_date: '',
    location: '',
    instructor: '',
    total_hours: '',
    notes: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        const t = props.target;
        form.name = t?.name ?? '';
        form.scheduled_date = t?.scheduled_date ?? '';
        form.location = t?.location ?? '';
        form.instructor = t?.instructor ?? '';
        form.total_hours = t?.total_hours ?? '';
        form.notes = t?.notes ?? '';
    },
);

async function submit(): Promise<void> {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    const blank = (v: string) => (v.trim() === '' ? null : v);
    const payload = {
        name: form.name,
        scheduled_date: form.scheduled_date,
        location: blank(form.location),
        instructor: blank(form.instructor),
        total_hours:
            form.total_hours.trim() === '' ? null : Number(form.total_hours),
        notes: blank(form.notes),
    };

    try {
        const detail =
            isEdit.value && props.target
                ? await store.update(props.target.id, payload)
                : await store.create(payload);
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
        <DialogContent>
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        A scheduled class. Attach trainings and enroll users on
                        the detail page; close it out later to record credit.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="grid gap-2">
                    <Label for="class_name">Name</Label>
                    <Input
                        id="class_name"
                        v-model="form.name"
                        required
                        autofocus
                    />
                    <InputError :message="fieldErrors.message('name')" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="class_date">Scheduled date</Label>
                        <Input
                            id="class_date"
                            type="date"
                            v-model="form.scheduled_date"
                            required
                        />
                        <InputError
                            :message="fieldErrors.message('scheduled_date')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="class_hours">Total hours</Label>
                        <Input
                            id="class_hours"
                            type="number"
                            step="0.25"
                            min="0"
                            v-model="form.total_hours"
                        />
                        <InputError
                            :message="fieldErrors.message('total_hours')"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="class_location">Location</Label>
                        <Input id="class_location" v-model="form.location" />
                        <InputError
                            :message="fieldErrors.message('location')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="class_instructor">Instructor</Label>
                        <Input
                            id="class_instructor"
                            v-model="form.instructor"
                        />
                        <InputError
                            :message="fieldErrors.message('instructor')"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="class_notes">Notes</Label>
                    <textarea
                        id="class_notes"
                        v-model="form.notes"
                        rows="2"
                        class="rounded border border-input bg-background px-3 py-2 text-sm"
                    />
                    <InputError :message="fieldErrors.message('notes')" />
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
                        {{ isEdit ? 'Save' : 'Create' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
