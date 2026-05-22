<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ClassFormFields } from '@/composables/useClassForm';
import { useFieldErrors } from '@/composables/useFieldErrors';

/**
 * The shared editable fields for a class, used by both the create modal and
 * the inline edit form on the detail page. Two-way bound via `v-model`; field
 * errors render from the error store under `context`.
 */
const form = defineModel<ClassFormFields>({ required: true });
const props = defineProps<{ context: string; idPrefix?: string }>();

const fieldErrors = useFieldErrors(props.context);
const id = (field: string) => `${props.idPrefix ?? 'class'}_${field}`;
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label :for="id('name')">Class name</Label>
            <Input
                :id="id('name')"
                name="class_name"
                v-model="form.name"
                autocomplete="off"
                data-1p-ignore
                data-lpignore="true"
                placeholder="e.g. Fall Protection — Spring"
            />
            <InputError :message="fieldErrors.message('name')" />
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-2">
                <Label :for="id('date')">Scheduled date</Label>
                <Input
                    :id="id('date')"
                    type="date"
                    v-model="form.scheduled_date"
                />
                <InputError
                    :message="fieldErrors.message('scheduled_date')"
                />
            </div>
            <div class="grid gap-2">
                <Label :for="id('hours')">Total hours</Label>
                <Input
                    :id="id('hours')"
                    type="number"
                    step="0.25"
                    min="0"
                    v-model="form.total_hours"
                />
                <InputError :message="fieldErrors.message('total_hours')" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-2">
                <Label :for="id('location')">Location</Label>
                <Input :id="id('location')" v-model="form.location" />
                <InputError :message="fieldErrors.message('location')" />
            </div>
            <div class="grid gap-2">
                <Label :for="id('instructor')">Instructor</Label>
                <Input :id="id('instructor')" v-model="form.instructor" />
                <InputError :message="fieldErrors.message('instructor')" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label :for="id('notes')">Notes</Label>
            <textarea
                :id="id('notes')"
                v-model="form.notes"
                rows="2"
                class="rounded border border-input bg-background px-3 py-2 text-sm"
            />
            <InputError :message="fieldErrors.message('notes')" />
        </div>
    </div>
</template>
