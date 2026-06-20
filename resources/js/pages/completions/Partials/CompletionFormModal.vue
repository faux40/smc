<script setup lang="ts">
/*
 * Single-completion form modal — create + edit.
 *
 * Create flow:
 *   1. Pick user.
 *   2. Pick module type (Training today; ALLOWED_MODULE_TYPES on the
 *      backend grows as future modules land).
 *   3. Pick the module record itself (e.g. which Training).
 *   4. Multi-select among "candidate elements" — every rqmt_element in
 *      the org that points at that exact (module_type, module_id). The
 *      admin picks which Requirements the completion credits.
 *   5. Fill completion_date + optional cert / expire dates + notes.
 *
 * On edit: user / module identity are locked (CompletionRequest pins
 * them from the existing record server-side). Element multi-select and
 * dates / notes can change; the pivot is re-sync'd by the controller.
 */
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
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
import { useFieldErrors } from '@/composables/useFieldErrors';
import { realtimeTabId } from '@/echo';
import { optionalNumber } from '@/lib/forms';
import { useCompletionsStore } from '@/stores/completions';
import type { CompletionRow } from '@/stores/completions';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';
import { useUsersStore } from '@/stores/users';

const FORM_CTX = 'form:completion';

type Mode = 'create' | 'edit';

interface CandidateElement {
    id: string;
    requirement_id: string;
    requirement_name: string | null;
    name: string;
    description: string | null;
}

// Mirrors ALLOWED_MODULE_TYPES in CompletionRequest. The string literal
// is the FQCN that the backend stores in `module_type`.
const ALLOWED_MODULE_TYPES = [
    { value: 'App\\Models\\Training', label: 'Training' },
];

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: CompletionRow | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'saved'): void;
}>();

const store = useCompletionsStore();
const trainings = useTrainingsStore();
const users = useUsersStore();

const candidates = ref<CandidateElement[]>([]);
const loadingCandidates = ref(false);
const loadError = ref<string | null>(null);

const form = reactive({
    user_id: '' as string,
    module_type: 'App\\Models\\Training' as string,
    module_id: '' as string,
    rqmt_element_ids: [] as string[],
    completion_date: new Date().toISOString().slice(0, 10),
    certification_date: '' as string,
    expire_date: '' as string,
    cert_ident: '' as string,
    hours: '' as string | number,
    notes: '' as string,
});
const submitting = ref(false);
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() =>
    isEdit.value ? 'Edit completion' : 'New completion',
);

// Last-name-first ordering via the store's backend-composed sortable name.
const sortedUsers = computed(() =>
    [...users.users].sort((a, b) =>
        (a.sort_name ?? '').localeCompare(b.sort_name ?? ''),
    ),
);
const sortedTrainings = computed(() =>
    [...trainings.library].sort((a, b) => a.name.localeCompare(b.name)),
);

const userName = (id: string) => users.displayName(id) || '—';
const trainingName = (id: string) =>
    trainings.library.find((t) => t.id === id)?.name ?? '—';

async function loadPickers(): Promise<void> {
    try {
        await Promise.all([users.loadPicker(), trainings.load()]);
    } catch (e) {
        loadError.value = (e as Error).message;
    }
}

async function loadCandidates(): Promise<void> {
    candidates.value = [];

    if (!form.module_type || !form.module_id) {
        return;
    }

    loadingCandidates.value = true;

    try {
        const { data } = await axios.get<CandidateElement[]>(
            '/api/rqmt-elements/candidates',
            {
                params: {
                    module_type: form.module_type,
                    module_id: form.module_id,
                },
                headers: defaultHeaders(),
            },
        );
        candidates.value = data;
    } catch (e) {
        loadError.value = (e as Error).message;
    } finally {
        loadingCandidates.value = false;
    }
}

onMounted(loadPickers);

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        loadError.value = null;

        if (isEdit.value && props.target) {
            form.user_id = props.target.user_id;
            form.module_type = props.target.module_type;
            form.module_id = props.target.module_id;
            form.rqmt_element_ids = [...props.target.rqmt_element_ids];
            form.completion_date =
                props.target.completion_date ??
                new Date().toISOString().slice(0, 10);
            form.certification_date = props.target.certification_date ?? '';
            form.expire_date = props.target.expire_date ?? '';
            form.cert_ident = props.target.cert_ident ?? '';
            form.hours = props.target.hours ?? '';
            form.notes = props.target.notes ?? '';
            await loadCandidates();
        } else {
            form.user_id = '';
            form.module_type = 'App\\Models\\Training';
            form.module_id = '';
            form.rqmt_element_ids = [];
            form.completion_date = new Date().toISOString().slice(0, 10);
            form.certification_date = '';
            form.expire_date = '';
            form.cert_ident = '';
            form.hours = '';
            form.notes = '';
            candidates.value = [];
        }
    },
);

// Re-fetch candidates whenever the module identity changes (create flow).
watch(
    () => [form.module_type, form.module_id] as [string, string],
    async ([t, id], [oldT, oldId]) => {
        if (isEdit.value) {
            return;
        }

        if (t === oldT && id === oldId) {
            return;
        }

        // Reset the multi-select when the module changes.
        form.rqmt_element_ids = [];
        await loadCandidates();
    },
);

const toggleElement = (id: string) => {
    const i = form.rqmt_element_ids.indexOf(id);

    if (i === -1) {
        form.rqmt_element_ids = [...form.rqmt_element_ids, id];
    } else {
        form.rqmt_element_ids = form.rqmt_element_ids.filter((x) => x !== id);
    }
};

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const payload = {
            user_id: form.user_id,
            module_type: form.module_type,
            module_id: form.module_id,
            rqmt_element_ids: form.rqmt_element_ids,
            completion_date: form.completion_date,
            certification_date:
                form.certification_date === '' ? null : form.certification_date,
            expire_date: form.expire_date === '' ? null : form.expire_date,
            cert_ident: form.cert_ident.trim() === '' ? null : form.cert_ident,
            hours: optionalNumber(form.hours),
            notes: form.notes.trim() === '' ? null : form.notes,
        };

        if (isEdit.value && props.target) {
            const {
                user_id: _u,
                module_type: _t,
                module_id: _m,
                ...editPayload
            } = payload;
            await store.update(props.target.id, editPayload);
        } else {
            await store.create(payload);
        }

        emit('saved');
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save completion',
        });
    } finally {
        submitting.value = false;
    }
};

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-xl">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Record that a user completed a module. Pick which
                        Requirements the completion credits — every element you
                        check must point at the same module.
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <p
                    v-if="loadError"
                    class="rounded bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"
                >
                    {{ loadError }}
                </p>

                <div class="grid gap-2">
                    <Label for="c_user">User</Label>
                    <Select v-model="form.user_id" :disabled="isEdit">
                        <SelectTrigger id="c_user">
                            <SelectValue placeholder="Pick a user…">
                                {{ form.user_id ? userName(form.user_id) : '' }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="u in sortedUsers"
                                :key="u.id"
                                :value="u.id"
                            >
                                {{ users.displayName(u.id) || u.id }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="isEdit" class="text-xs text-muted-foreground">
                        User and module are locked after creation.
                    </p>
                    <InputError :message="fieldErrors.message('user_id')" />
                </div>

                <div class="grid gap-2">
                    <Label for="c_mtype">Module type</Label>
                    <Select v-model="form.module_type" :disabled="isEdit">
                        <SelectTrigger id="c_mtype">
                            <SelectValue placeholder="Pick a module type…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="t in ALLOWED_MODULE_TYPES"
                                :key="t.value"
                                :value="t.value"
                            >
                                {{ t.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="fieldErrors.message('module_type')" />
                </div>

                <div class="grid gap-2">
                    <Label for="c_mid">Module</Label>
                    <Select v-model="form.module_id" :disabled="isEdit">
                        <SelectTrigger id="c_mid">
                            <SelectValue placeholder="Pick a training…">
                                {{
                                    form.module_id
                                        ? trainingName(form.module_id)
                                        : ''
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="t in sortedTrainings"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="fieldErrors.message('module_id')" />
                </div>

                <div class="grid gap-2">
                    <Label>Credit toward these elements</Label>
                    <p
                        v-if="loadingCandidates"
                        class="text-xs text-muted-foreground"
                    >
                        Loading candidate elements…
                    </p>
                    <p
                        v-else-if="candidates.length === 0 && form.module_id"
                        class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                    >
                        No rqmt_elements in this org point at the selected
                        module yet. Add an element on a Requirement first.
                    </p>
                    <div
                        v-else-if="candidates.length > 0"
                        class="max-h-48 overflow-y-auto rounded border border-border"
                    >
                        <label
                            v-for="c in candidates"
                            :key="c.id"
                            class="flex items-start gap-2 border-b border-border px-3 py-2 last:border-b-0 hover:bg-muted/30"
                        >
                            <Checkbox
                                :model-value="
                                    form.rqmt_element_ids.includes(c.id)
                                "
                                @update:model-value="toggleElement(c.id)"
                            />
                            <span class="text-sm">
                                {{ c.name }}
                                <span class="text-xs text-muted-foreground">
                                    · {{ c.requirement_name ?? '—' }}
                                </span>
                            </span>
                        </label>
                    </div>
                    <InputError
                        :message="fieldErrors.message('rqmt_element_ids')"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="c_compdate">Completion date</Label>
                        <Input
                            id="c_compdate"
                            type="date"
                            v-model="form.completion_date"
                            required
                        />
                        <InputError
                            :message="fieldErrors.message('completion_date')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c_certdate">Certification date</Label>
                        <Input
                            id="c_certdate"
                            type="date"
                            v-model="form.certification_date"
                        />
                        <InputError
                            :message="fieldErrors.message('certification_date')"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="c_expire">Expire date</Label>
                        <Input
                            id="c_expire"
                            type="date"
                            v-model="form.expire_date"
                        />
                        <InputError
                            :message="fieldErrors.message('expire_date')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c_ident">Cert identifier</Label>
                        <Input
                            id="c_ident"
                            v-model="form.cert_ident"
                            placeholder="e.g. cert #"
                        />
                        <InputError
                            :message="fieldErrors.message('cert_ident')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c_hours">Hours (optional)</Label>
                        <Input
                            id="c_hours"
                            type="number"
                            min="0"
                            step="0.25"
                            v-model="form.hours"
                        />
                        <InputError :message="fieldErrors.message('hours')" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="c_notes">Notes</Label>
                    <textarea
                        id="c_notes"
                        v-model="form.notes"
                        rows="2"
                        class="w-full rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
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
                        {{ submitting ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
