<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';
import { useTrainingsStore, type TrainingRow } from '@/stores/trainings';

type Mode = 'create' | 'edit';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: TrainingRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const trainings = useTrainingsStore();
const frequencies = useStdFrequenciesStore();

interface FormState {
    name: string;
    description: string;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
}

const form = reactive<FormState>({
    name: '',
    description: '',
    initial_only: false,
    repeating: false,
    std_freq_id: null,
    as_needed: false,
});

const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit training' : 'New training'));

onMounted(async () => {
    if (!frequencies.loaded) {
        try {
            await frequencies.load();
        } catch {
            // Non-fatal — the picker will be empty.
        }
    }
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errors.value = {};
        if (isEdit.value && props.target) {
            const t = props.target;
            form.name = t.name;
            form.description = t.description ?? '';
            form.initial_only = t.initial_only;
            form.repeating = t.repeating;
            form.std_freq_id = t.std_freq_id;
            form.as_needed = t.as_needed;
        } else {
            form.name = '';
            form.description = '';
            form.initial_only = false;
            form.repeating = false;
            form.std_freq_id = null;
            form.as_needed = false;
        }
    },
);

// When unchecking repeating, drop the frequency.
watch(
    () => form.repeating,
    (next) => {
        if (!next) form.std_freq_id = null;
    },
);

const submit = async () => {
    submitting.value = true;
    errors.value = {};
    try {
        const payload = {
            name: form.name,
            description: form.description.trim() === '' ? null : form.description,
            initial_only: form.initial_only,
            repeating: form.repeating,
            std_freq_id: form.repeating ? form.std_freq_id : null,
            as_needed: form.as_needed,
        };

        if (isEdit.value && props.target) {
            await trainings.update(props.target.id, payload);
        } else {
            await trainings.create(payload);
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
                        Template for a training. The three timing flags get
                        copied into rqmt_elements when this training is added
                        to a Requirement.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="t_name">Name</Label>
                    <Input id="t_name" v-model="form.name" required autofocus />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="t_desc">Description</Label>
                    <textarea
                        id="t_desc"
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                    <InputError :message="errors.description" />
                </div>

                <div class="space-y-2">
                    <p class="text-sm font-medium">Timing</p>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.initial_only" />
                        Initial-only (one-time on assignment)
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.repeating" />
                        Repeating
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.as_needed" />
                        As-needed (no schedule; just available to record)
                    </label>
                    <InputError :message="errors.initial_only" />
                    <InputError :message="errors.repeating" />
                    <InputError :message="errors.as_needed" />
                    <p class="text-xs text-muted-foreground">
                        At least one must be set. Initial-only and repeating
                        are mutually exclusive.
                    </p>
                </div>

                <div v-if="form.repeating" class="grid gap-2">
                    <Label for="t_freq">Frequency</Label>
                    <Select v-model="form.std_freq_id">
                        <SelectTrigger id="t_freq">
                            <SelectValue placeholder="Pick a frequency…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="f in frequencies.library"
                                :key="f.id"
                                :value="f.id"
                            >
                                {{ f.name }} ({{ f.repeat_days }} day{{
                                    f.repeat_days === 1 ? '' : 's'
                                }})
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.std_freq_id" />
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
                        {{ submitting ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
