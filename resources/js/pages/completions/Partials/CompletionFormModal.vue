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
 *
 * Quick-action prefill (F7): callers with a known user/training in hand
 * (users/Show row actions, the needs-action dashboard widget) pass
 * initial-user-id / initial-training-id. Mirrors
 * TrainingAssignmentFormModal's initial-user-id convention — a supplied
 * initial value preselects the field AND locks its picker (the same way
 * edit mode locks user/module), since the caller already knows the
 * identity and re-picking it would be redundant. The standalone
 * completions/Index "+ New completion" flow passes neither prop, so both
 * pickers stay open exactly as before.
 *
 * Expiry auto-fill (F9): a blank expire_date used to silently read as
 * "Current forever" in the compliance reports. When the selected training
 * repeats on a std frequency, expire_date auto-computes as
 * completion_date + repeat_days whenever the training or completion_date
 * changes. Rule for who wins: the moment the admin hand-types into the
 * expire field, that value sticks for the rest of this open — training/date
 * changes no longer touch it (simplest to reason about; a hand-typed value
 * is never silently clobbered again). Re-opening the modal resets the
 * tracking. In edit mode, opening never overwrites the stored value —
 * auto-fill only engages after the admin changes completion_date (module is
 * locked in edit mode, so it can't change there).
 */
import axios from 'axios';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
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
import { addDaysToDateOnly } from '@/lib/dateOnly';
import { optionalNumber } from '@/lib/forms';
import { useCompletionsStore } from '@/stores/completions';
import type {
    CompletionBulkResult,
    CompletionRow,
} from '@/stores/completions';
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
    initialUserId?: string | null;
    initialTrainingId?: string | null;
    // F8 multi-user mode: record this one training for a set of already-picked
    // users at once. When non-empty, the single user picker is replaced by a
    // read-only summary and save posts to the bulk endpoint. Pair with
    // initialTrainingId so the module is locked too.
    userIds?: string[] | null;
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'saved', result?: CompletionBulkResult): void;
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
// Multi-user (bulk) mode: a non-empty userIds list means "record this training
// for all of them at once". Never on in edit mode.
const isMulti = computed(
    () => !isEdit.value && (props.userIds?.length ?? 0) > 0,
);
const multiCount = computed(() => props.userIds?.length ?? 0);
const title = computed(() => {
    if (isEdit.value) {
        return 'Edit completion';
    }
    if (isMulti.value) {
        return `Record completion for ${multiCount.value} ${multiCount.value === 1 ? 'user' : 'users'}`;
    }
    return 'New completion';
});

// A short, read-only preview of who's being recorded (first few names, then a
// "+N more" tail) — the picker is redundant when the caller already chose them.
const MULTI_PREVIEW = 5;
const multiNames = computed(() =>
    (props.userIds ?? []).slice(0, MULTI_PREVIEW).map((id) => userName(id)),
);
const multiMore = computed(() => Math.max(0, multiCount.value - MULTI_PREVIEW));

// Locked exactly like edit mode locks user/module — the caller (a row
// action) already knows the identity, so the picker is redundant. Only
// engaged when the corresponding prop is actually supplied, so the
// standalone create flow is unaffected.
const userLocked = computed(() => isEdit.value || Boolean(props.initialUserId));
const moduleLocked = computed(
    () => isEdit.value || Boolean(props.initialTrainingId),
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

// F9 — expire_date auto-fill from the selected training's repeat frequency.
// See the header comment for the manual-edit rule.
const selectedTraining = computed(
    () => trainings.library.find((t) => t.id === form.module_id) ?? null,
);
// Mirrors CompleteClass's own `repeating && repeat_days` check — as-needed /
// initial-only trainings (repeating=false) never expire.
const repeatDays = computed<number | null>(() => {
    const t = selectedTraining.value;

    return t?.repeating ? (t.std_freq_repeat_days ?? null) : null;
});
// True once the admin has hand-typed into the expire field this time the
// modal is open — from then on, training/date changes leave it alone.
const expireDirty = ref(false);
// True only while the field's current value was actually produced by
// autoFillExpiry() below (as opposed to a loaded/stored value on edit-mode
// open) — drives the helper text so it never mislabels a stored value as
// "Auto".
const expireAutoFilled = ref(false);
// Guards the module_id/completion_date watchers below while the open-prop
// handler is (re)initializing the form, so re-populating those fields on
// open never itself counts as a "training/date changed" auto-fill trigger.
let suppressAutoFill = false;

function autoFillExpiry(): void {
    if (suppressAutoFill || expireDirty.value) {
        return;
    }

    if (repeatDays.value === null || !form.completion_date) {
        form.expire_date = '';
        expireAutoFilled.value = false;

        return;
    }

    form.expire_date = addDaysToDateOnly(form.completion_date, repeatDays.value);
    expireAutoFilled.value = true;
}

function onExpireInput(value: string | number): void {
    form.expire_date = String(value);
    expireDirty.value = true;
    expireAutoFilled.value = false;
}

const expireHelper = computed(() => {
    if (!expireAutoFilled.value || repeatDays.value === null) {
        return null;
    }

    const days = repeatDays.value;

    return `Auto: ${days} day${days === 1 ? '' : 's'} from completion (per training frequency)`;
});

watch(() => form.module_id, autoFillExpiry);
watch(() => form.completion_date, autoFillExpiry);

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

        // Reset the auto-fill tracking for this open, and suppress the
        // module_id/completion_date watchers below while we (re)populate the
        // form — none of these programmatic sets count as a real "training
        // or date changed" trigger.
        expireDirty.value = false;
        expireAutoFilled.value = false;
        suppressAutoFill = true;

        if (isEdit.value && props.target) {
            form.user_id = props.target.user_id;
            form.module_type = props.target.module_type;
            form.module_id = props.target.module_id;
            form.rqmt_element_ids = [...props.target.rqmt_element_ids];
            form.completion_date =
                props.target.completion_date ??
                new Date().toISOString().slice(0, 10);
            form.certification_date = props.target.certification_date ?? '';
            // Edit mode: never auto-overwrite the stored expiry on open —
            // auto-fill only re-engages once the admin changes
            // completion_date (module is locked, so it can't change here).
            form.expire_date = props.target.expire_date ?? '';
            form.cert_ident = props.target.cert_ident ?? '';
            form.hours = props.target.hours ?? '';
            form.notes = props.target.notes ?? '';
        } else {
            form.user_id = props.initialUserId ?? '';
            form.module_type = 'App\\Models\\Training';
            form.module_id = props.initialTrainingId ?? '';
            form.rqmt_element_ids = [];
            form.completion_date = new Date().toISOString().slice(0, 10);
            form.certification_date = '';
            form.expire_date = '';
            form.cert_ident = '';
            form.hours = '';
            form.notes = '';
            candidates.value = [];
        }

        // Let the watchers triggered by the sets above flush while still
        // suppressed, then release them for genuine post-open changes.
        await nextTick();
        suppressAutoFill = false;

        if (isEdit.value && props.target) {
            await loadCandidates();
        } else {
            if (form.module_id) {
                await loadCandidates();
            }
            // Create/prefill flow only: compute the initial expiry now that
            // suppression is lifted (a preselected training already has its
            // frequency known).
            autoFillExpiry();
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
            emit('saved');
        } else if (isMulti.value) {
            const {
                user_id: _u,
                module_type: _t,
                module_id: _m,
                ...fields
            } = payload;
            const result = await store.bulkCreate({
                user_ids: props.userIds ?? [],
                training_id: form.module_id,
                ...fields,
            });
            emit('saved', result);
        } else {
            await store.create(payload);
            emit('saved');
        }

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

                <!-- Multi-user (bulk) mode: a read-only summary stands in for
                     the picker — the caller already chose the roster. -->
                <div v-if="isMulti" class="grid gap-2" data-testid="multi-user-summary">
                    <Label>Users</Label>
                    <div
                        class="rounded border border-border bg-muted/30 p-3 text-sm"
                    >
                        <p class="font-medium">
                            Recording for {{ multiCount }}
                            {{ multiCount === 1 ? 'selected user' : 'selected users' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ multiNames.join(', ')
                            }}<template v-if="multiMore > 0">
                                and {{ multiMore }} more</template
                            >
                        </p>
                    </div>
                </div>
                <div v-else class="grid gap-2">
                    <Label for="c_user">User</Label>
                    <Select v-model="form.user_id" :disabled="userLocked">
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
                    <p
                        v-else-if="initialUserId"
                        class="text-xs text-muted-foreground"
                    >
                        User preselected.
                    </p>
                    <InputError :message="fieldErrors.message('user_id')" />
                </div>

                <div class="grid gap-2">
                    <Label for="c_mtype">Module type</Label>
                    <Select v-model="form.module_type" :disabled="moduleLocked">
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
                    <Select v-model="form.module_id" :disabled="moduleLocked">
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
                    <p
                        v-if="!isEdit && initialTrainingId"
                        class="text-xs text-muted-foreground"
                    >
                        Training preselected.
                    </p>
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
                            :model-value="form.expire_date"
                            @update:model-value="onExpireInput"
                        />
                        <p
                            v-if="expireHelper"
                            data-testid="expire-auto-helper"
                            class="text-xs text-muted-foreground"
                        >
                            {{ expireHelper }}
                        </p>
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
