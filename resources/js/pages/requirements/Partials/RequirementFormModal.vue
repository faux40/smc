<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
import { useRequirementsStore, type RequirementRow } from '@/stores/requirements';

type Mode = 'create' | 'edit';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: RequirementRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useRequirementsStore();

const form = reactive({ name: '', description: '' });
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit requirement' : 'New requirement'));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errors.value = {};
        if (isEdit.value && props.target) {
            form.name = props.target.name;
            form.description = props.target.description ?? '';
        } else {
            form.name = '';
            form.description = '';
        }
    },
);

const submit = async () => {
    submitting.value = true;
    errors.value = {};
    try {
        const payload = {
            name: form.name,
            description: form.description.trim() === '' ? null : form.description,
        };
        if (isEdit.value && props.target) {
            await store.update(props.target.id, payload);
        } else {
            await store.create(payload);
        }
        emit('update:open', false);
    } catch (e: unknown) {
        const err = e as { response?: { data?: { errors?: Record<string, string[]> } } };
        const errs = err.response?.data?.errors;
        if (errs) {
            errors.value = Object.fromEntries(
                Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']),
            );
        }
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        A named group of rqmt_elements. Add elements (Trainings
                        today; future Inspections / Certs / etc.) on the
                        Requirement detail page.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="r_name">Name</Label>
                    <Input id="r_name" v-model="form.name" required autofocus />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="r_desc">Description</Label>
                    <textarea
                        id="r_desc"
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                    <InputError :message="errors.description" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="emit('update:open', false)">
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
