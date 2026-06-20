<script setup lang="ts">
import { onMounted, watch } from 'vue';
import CertEditor from '@/components/CertEditor.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFieldErrors } from '@/composables/useFieldErrors';
import type { TrainingFormState } from '@/lib/trainingForm';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';

/**
 * The shared editable fields for a training, used by both the create modal
 * (TrainingFormModal) and the inline edit form on the detail page
 * (trainings/Show). Two-way bound via `v-model`; field errors render from the
 * error store under `context`.
 */
const form = defineModel<TrainingFormState>({ required: true });
const props = defineProps<{ context: string }>();

const frequencies = useStdFrequenciesStore();
const fieldErrors = useFieldErrors(props.context);

onMounted(async () => {
    if (!frequencies.loaded) {
        try {
            await frequencies.load();
        } catch {
            // Non-fatal — the picker will be empty.
        }
    }
});

// Unchecking "repeating" drops the frequency.
watch(
    () => form.value.repeating,
    (next) => {
        if (!next) {
            form.value.std_freq_id = null;
        }
    },
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="t_name">Name</Label>
            <Input id="t_name" v-model="form.name" required autofocus />
            <InputError :message="fieldErrors.message('name')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_nickname">Nickname (optional)</Label>
            <Input
                id="t_nickname"
                v-model="form.nickname"
                placeholder="Short alias, e.g. FallPro"
            />
            <InputError :message="fieldErrors.message('nickname')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_desc">Description</Label>
            <textarea
                id="t_desc"
                v-model="form.description"
                rows="3"
                class="w-full rounded border border-input bg-background p-2 text-sm"
            ></textarea>
            <InputError :message="fieldErrors.message('description')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_hours">Default hours</Label>
            <Input
                id="t_hours"
                type="number"
                step="0.25"
                min="0"
                v-model="form.default_hours"
                class="w-32"
                placeholder="e.g. 4"
            />
            <p class="text-xs text-muted-foreground">
                Pre-fills the hours when this topic is added to a class.
            </p>
            <InputError :message="fieldErrors.message('default_hours')" />
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
            <InputError :message="fieldErrors.message('initial_only')" />
            <InputError :message="fieldErrors.message('repeating')" />
            <InputError :message="fieldErrors.message('as_needed')" />
            <p class="text-xs text-muted-foreground">
                At least one must be set. Initial-only and repeating are
                mutually exclusive.
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
            <InputError :message="fieldErrors.message('std_freq_id')" />
        </div>

        <div class="space-y-3 border-t border-border pt-3">
            <p class="text-sm font-medium">Certificate</p>
            <p class="text-xs text-muted-foreground">
                Defaults copied onto a class when this topic is added, then
                printed on the certificate.
            </p>

            <CertEditor
                v-model:title="form.cert_title"
                v-model:text="form.cert_text"
                :context="context"
            />

            <div class="grid gap-2">
                <Label for="t_cert_code">Cert code</Label>
                <Input
                    id="t_cert_code"
                    v-model="form.cert_code"
                    placeholder="e.g. FPAP"
                />
                <InputError :message="fieldErrors.message('cert_code')" />
            </div>

            <div class="grid gap-2">
                <Label for="t_def_trainer">Default trainer</Label>
                <Input id="t_def_trainer" v-model="form.default_trainer" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="t_def_loc">Default location</Label>
                    <Input id="t_def_loc" v-model="form.default_location" />
                </div>
                <div class="grid gap-2">
                    <Label for="t_def_addr">Default address</Label>
                    <textarea
                        id="t_def_addr"
                        v-model="form.default_address"
                        rows="2"
                        class="rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                </div>
            </div>
        </div>
    </div>
</template>
