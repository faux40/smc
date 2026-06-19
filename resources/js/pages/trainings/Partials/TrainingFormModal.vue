<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import MarkdownField from '@/components/MarkdownField.vue';
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
import { useFieldErrors } from '@/composables/useFieldErrors';
import { optionalNumber } from '@/lib/forms';
import { useErrorStore } from '@/stores/errors';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';
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
const frequencies = useStdFrequenciesStore();

interface FormState {
    name: string;
    nickname: string;
    description: string;
    default_hours: string | number;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
    // Certificate content defaults.
    cert_title: string;
    cert_text: string;
    lifespan_months: string | number;
    cert_code: string;
    default_trainer: string;
    default_location: string;
    default_address: string;
}

function blankCert(): Pick<
    FormState,
    | 'cert_title'
    | 'cert_text'
    | 'lifespan_months'
    | 'cert_code'
    | 'default_trainer'
    | 'default_location'
    | 'default_address'
> {
    return {
        cert_title: '',
        cert_text: '',
        lifespan_months: '',
        cert_code: '',
        default_trainer: '',
        default_location: '',
        default_address: '',
    };
}

const form = reactive<FormState>({
    name: '',
    nickname: '',
    description: '',
    default_hours: '',
    initial_only: false,
    repeating: false,
    std_freq_id: null,
    as_needed: false,
    ...blankCert(),
});

const submitting = ref(false);
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

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
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);

        if (isEdit.value && props.target) {
            const t = props.target;
            form.name = t.name;
            form.nickname = t.nickname ?? '';
            form.description = t.description ?? '';
            form.default_hours = t.default_hours ?? '';
            form.initial_only = t.initial_only;
            form.repeating = t.repeating;
            form.std_freq_id = t.std_freq_id;
            form.as_needed = t.as_needed;
            form.cert_title = t.cert_title ?? '';
            form.cert_text = t.cert_text ?? '';
            form.lifespan_months = t.lifespan_months ?? '';
            form.cert_code = t.cert_code ?? '';
            form.default_trainer = t.default_trainer ?? '';
            form.default_location = t.default_location ?? '';
            form.default_address = t.default_address ?? '';
        } else {
            form.name = '';
            form.nickname = '';
            form.description = '';
            form.default_hours = '';
            form.initial_only = false;
            form.repeating = false;
            form.std_freq_id = null;
            form.as_needed = false;
            Object.assign(form, blankCert());
        }
    },
);

// When unchecking repeating, drop the frequency.
watch(
    () => form.repeating,
    (next) => {
        if (!next) {
            form.std_freq_id = null;
        }
    },
);

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const blank = (v: string) => (v.trim() === '' ? null : v);
        const payload = {
            name: form.name,
            nickname: blank(form.nickname),
            description: blank(form.description),
            default_hours: optionalNumber(form.default_hours),
            initial_only: form.initial_only,
            repeating: form.repeating,
            std_freq_id: form.repeating ? form.std_freq_id : null,
            as_needed: form.as_needed,
            cert_title: blank(form.cert_title),
            cert_text: blank(form.cert_text),
            lifespan_months: optionalNumber(form.lifespan_months),
            cert_code: blank(form.cert_code),
            default_trainer: blank(form.default_trainer),
            default_location: blank(form.default_location),
            default_address: blank(form.default_address),
        };

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
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
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
                    <InputError
                        :message="fieldErrors.message('initial_only')"
                    />
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
                        Defaults copied onto a class when this topic is added,
                        then printed on the certificate.
                    </p>

                    <div class="grid gap-2">
                        <Label for="t_cert_title">Certificate title</Label>
                        <Input
                            id="t_cert_title"
                            v-model="form.cert_title"
                            placeholder="e.g. Fall Protection Authorized Person"
                        />
                    </div>

                    <div class="grid gap-2">
                        <Label for="t_cert_text">Certificate text</Label>
                        <MarkdownField
                            id="t_cert_text"
                            v-model="form.cert_text"
                            :rows="4"
                            placeholder="Satisfies **Cal/OSHA** requirements…"
                        />
                        <p class="text-xs text-muted-foreground">
                            Markdown: blank lines start a new paragraph,
                            <code>**bold**</code> and <code>*italic*</code> are
                            supported. Printed on the certificate body.
                        </p>
                        <InputError :message="fieldErrors.message('cert_text')" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="t_lifespan">Lifespan (months)</Label>
                            <Input
                                id="t_lifespan"
                                type="number"
                                min="0"
                                step="1"
                                v-model="form.lifespan_months"
                                placeholder="e.g. 24"
                            />
                            <InputError
                                :message="fieldErrors.message('lifespan_months')"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="t_cert_code">Cert code</Label>
                            <Input
                                id="t_cert_code"
                                v-model="form.cert_code"
                                placeholder="e.g. FPAP"
                            />
                            <InputError
                                :message="fieldErrors.message('cert_code')"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="t_def_trainer">Default trainer</Label>
                        <Input
                            id="t_def_trainer"
                            v-model="form.default_trainer"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="t_def_loc">Default location</Label>
                            <Input
                                id="t_def_loc"
                                v-model="form.default_location"
                            />
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
