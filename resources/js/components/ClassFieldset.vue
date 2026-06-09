<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
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
const props = defineProps<{
    context: string;
    idPrefix?: string;
}>();

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

        <!-- Consumers can drop content (e.g. the topics list) directly under
             the name field so it reads as part of the form. -->
        <slot name="after-name" />

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
                <Label :for="id('total_hours')">Class hours</Label>
                <Input
                    :id="id('total_hours')"
                    type="number"
                    min="0"
                    step="0.5"
                    v-model="form.total_hours"
                    placeholder="e.g. 8"
                />
                <InputError :message="fieldErrors.message('total_hours')" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-2">
                <Label :for="id('start_time')">Start time</Label>
                <Input
                    :id="id('start_time')"
                    type="time"
                    v-model="form.start_time"
                />
                <InputError :message="fieldErrors.message('start_time')" />
            </div>
            <div class="grid gap-2">
                <Label :for="id('end_time')">End time</Label>
                <Input
                    :id="id('end_time')"
                    type="time"
                    v-model="form.end_time"
                />
                <InputError :message="fieldErrors.message('end_time')" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-2">
                <Label :for="id('location')">Location</Label>
                <Input
                    :id="id('location')"
                    v-model="form.location"
                    placeholder="e.g. VSFCD Training Room"
                />
                <InputError :message="fieldErrors.message('location')" />
            </div>
            <div class="grid gap-2">
                <Label :for="id('instructor')">Trainer / instructor</Label>
                <Input :id="id('instructor')" v-model="form.instructor" />
                <label
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Checkbox v-model="form.show_signature" />
                    Show signature on certificate
                </label>
                <InputError :message="fieldErrors.message('instructor')" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label :for="id('address')">Address</Label>
            <textarea
                :id="id('address')"
                v-model="form.address"
                rows="2"
                class="rounded border border-input bg-background px-3 py-2 text-sm"
                placeholder="450 Ryder St&#10;Vallejo, CA 94590"
            />
            <InputError :message="fieldErrors.message('address')" />
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
