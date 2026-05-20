<script setup lang="ts">
/*
 * Single-assignment form modal — create + edit.
 *
 * On create: pick user + requirement + timing + start_date. Server is
 * responsible for copying the requirement name + description onto the
 * assignment row.
 *
 * On edit: user_id + requirement_id are locked (the AssignmentRequest
 * server-side disallows re-targeting an existing assignment). Only
 * name / description / timing / dates can change.
 *
 * Picker data:
 *  - Users via /api/users (Manager+ gated).
 *  - Requirements via useRequirementsStore.
 *  - Frequencies via useStdFrequenciesStore.
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
import { useAssignmentsStore } from '@/stores/assignments';
import type { AssignmentRow } from '@/stores/assignments';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';

const FORM_CTX = 'form:assignment';

type Mode = 'create' | 'edit';

interface UserPickerRow {
    id: string;
    name: string;
    f_name: string;
    l_name: string;
    email: string | null;
}

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: AssignmentRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useAssignmentsStore();
const requirements = useRequirementsStore();
const frequencies = useStdFrequenciesStore();

const userPicker = ref<UserPickerRow[]>([]);
const pickersLoaded = ref(false);
const loadError = ref<string | null>(null);

const form = reactive({
    user_id: '' as string,
    requirement_id: '' as string,
    name: '' as string,
    description: '' as string,
    initial_only: false,
    repeating: true,
    std_freq_id: null as string | null,
    as_needed: false,
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '' as string,
});
const submitting = ref(false);
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() =>
    isEdit.value ? 'Edit assignment' : 'New assignment',
);

const sortedUsers = computed(() => [...userPicker.value]);
const sortedRequirements = computed(() =>
    [...requirements.library].sort((a, b) => a.name.localeCompare(b.name)),
);
const userName = (id: string) => {
    const u = userPicker.value.find((x) => x.id === id);

    return u ? [u.f_name, u.l_name].filter(Boolean).join(' ') : '—';
};
const requirementName = (id: string) =>
    requirements.library.find((r) => r.id === id)?.name ?? '—';

onMounted(async () => {
    await loadPickers();
});

async function loadPickers(): Promise<void> {
    if (pickersLoaded.value) {
        return;
    }

    try {
        const [u] = await Promise.all([
            axios.get<UserPickerRow[]>('/api/users', {
                headers: defaultHeaders(),
            }),
            requirements.load(),
            frequencies.load(),
        ]);
        userPicker.value = u.data;
        pickersLoaded.value = true;
    } catch (e) {
        loadError.value = (e as Error).message;
    }
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);
        loadError.value = null;
        await loadPickers();

        if (isEdit.value && props.target) {
            form.user_id = props.target.user_id;
            form.requirement_id = props.target.requirement_id;
            form.name = props.target.name;
            form.description = props.target.description ?? '';
            form.initial_only = props.target.initial_only;
            form.repeating = props.target.repeating;
            form.std_freq_id = props.target.std_freq_id;
            form.as_needed = props.target.as_needed;
            form.start_date =
                props.target.start_date ??
                new Date().toISOString().slice(0, 10);
            form.end_date = props.target.end_date ?? '';
        } else {
            form.user_id = '';
            form.requirement_id = '';
            form.name = '';
            form.description = '';
            form.initial_only = false;
            form.repeating = true;
            form.std_freq_id = null;
            form.as_needed = false;
            form.start_date = new Date().toISOString().slice(0, 10);
            form.end_date = '';
        }
    },
);

// Default the name + description from the chosen requirement on create.
// Don't overwrite if the admin already typed something.
watch(
    () => form.requirement_id,
    (id) => {
        if (isEdit.value) {
            return;
        }

        if (!id) {
            return;
        }

        const r = requirements.library.find((x) => x.id === id);

        if (!r) {
            return;
        }

        if (form.name.trim() === '') {
            form.name = r.name;
        }

        if (form.description.trim() === '') {
            form.description = r.description ?? '';
        }
    },
);

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        const payload = {
            user_id: form.user_id,
            requirement_id: form.requirement_id,
            name: form.name,
            description:
                form.description.trim() === '' ? null : form.description,
            initial_only: form.initial_only,
            repeating: form.repeating,
            std_freq_id: form.repeating ? form.std_freq_id : null,
            as_needed: form.as_needed,
            start_date: form.start_date,
            end_date: form.end_date === '' ? null : form.end_date,
        };

        if (isEdit.value && props.target) {
            const { user_id: _u, requirement_id: _r, ...editPayload } = payload;
            await store.update(props.target.id, editPayload);
        } else {
            await store.create(payload);
        }

        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save assignment',
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
        <DialogContent class="sm:max-w-lg">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Per-(user, requirement) timing record. Bulk-creating
                        across many users? Use Bulk assign in the nav.
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
                    <Label for="a_user">User</Label>
                    <Select v-model="form.user_id" :disabled="isEdit">
                        <SelectTrigger id="a_user">
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
                                {{
                                    [u.f_name, u.l_name]
                                        .filter(Boolean)
                                        .join(' ') ||
                                    u.email ||
                                    u.id
                                }}
                                <span
                                    v-if="u.email"
                                    class="text-xs text-muted-foreground"
                                >
                                    · {{ u.email }}</span
                                >
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="isEdit" class="text-xs text-muted-foreground">
                        User can't change after creation — delete + recreate to
                        re-target.
                    </p>
                    <InputError :message="fieldErrors.message('user_id')" />
                </div>

                <div class="grid gap-2">
                    <Label for="a_req">Requirement</Label>
                    <Select v-model="form.requirement_id" :disabled="isEdit">
                        <SelectTrigger id="a_req">
                            <SelectValue placeholder="Pick a requirement…">
                                {{
                                    form.requirement_id
                                        ? requirementName(form.requirement_id)
                                        : ''
                                }}
                            </SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="r in sortedRequirements"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        :message="fieldErrors.message('requirement_id')"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="a_name">Name</Label>
                    <Input id="a_name" v-model="form.name" required />
                    <InputError :message="fieldErrors.message('name')" />
                </div>

                <div class="grid gap-2">
                    <Label for="a_desc">Description</Label>
                    <textarea
                        id="a_desc"
                        v-model="form.description"
                        rows="2"
                        class="w-full rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                    <InputError :message="fieldErrors.message('description')" />
                </div>

                <div class="space-y-2">
                    <p class="text-sm font-medium">Timing</p>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.initial_only" />
                        Initial-only
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.repeating" />
                        Repeating
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="form.as_needed" />
                        As-needed
                    </label>
                    <InputError
                        :message="fieldErrors.message('initial_only')"
                    />
                    <InputError :message="fieldErrors.message('repeating')" />
                    <InputError :message="fieldErrors.message('as_needed')" />
                </div>

                <div v-if="form.repeating" class="grid gap-2">
                    <Label for="a_freq">Frequency</Label>
                    <Select v-model="form.std_freq_id">
                        <SelectTrigger id="a_freq">
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

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="a_start">Start date</Label>
                        <Input
                            id="a_start"
                            type="date"
                            v-model="form.start_date"
                            required
                        />
                        <InputError
                            :message="fieldErrors.message('start_date')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="a_end">End date (optional)</Label>
                        <Input id="a_end" type="date" v-model="form.end_date" />
                        <InputError
                            :message="fieldErrors.message('end_date')"
                        />
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
