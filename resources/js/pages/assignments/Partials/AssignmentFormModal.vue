<script setup lang="ts">
/*
 * Single-assignment form modal — create + edit.
 *
 * On create: pick user + requirement + start_date. The server owns the
 * assignment name (a snapshot of the requirement); the client never sends
 * it. `initialUserId` pre-selects the user for the per-row quick-add.
 *
 * On edit: user_id + requirement_id are locked (the AssignmentRequest
 * server-side disallows re-targeting an existing assignment). Only
 * description / dates change. Edit mode also offers Delete.
 *
 * Timing is not set here — it lives on the requirement's elements. The
 * editor shows them read-only so the admin sees the schedule being assigned.
 *
 * Picker data:
 *  - Users via /api/users (Manager+ gated).
 *  - Requirements via useRequirementsStore.
 *  - Elements via useRqmtElementsStore (read-only preview).
 */
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { realtimeTabId } from '@/echo';
import { elementTimingLabel } from '@/lib/timing';
import { useAssignmentsStore } from '@/stores/assignments';
import type { AssignmentRow } from '@/stores/assignments';
import { useErrorStore } from '@/stores/errors';
import { useRequirementsStore } from '@/stores/requirements';
import { useRqmtElementsStore } from '@/stores/rqmtElements';

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
    initialUserId?: string | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useAssignmentsStore();
const requirements = useRequirementsStore();
const rqmtElements = useRqmtElementsStore();

const userPicker = ref<UserPickerRow[]>([]);
const pickersLoaded = ref(false);
const loadError = ref<string | null>(null);

const form = reactive({
    user_id: '' as string,
    requirement_id: '' as string,
    description: '' as string,
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '' as string,
});
const submitting = ref(false);
const deleting = ref(false);
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() =>
    isEdit.value ? 'Edit assignment' : 'New assignment',
);
// Lock the user picker on edit, and on the per-row quick-add (where the
// user is pre-selected via initialUserId). The top-level "+ New assignment"
// leaves initialUserId null, so the picker stays free.
const userLocked = computed(() => isEdit.value || Boolean(props.initialUserId));

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

// Read-only preview of the requirement's elements + their timing, so the
// admin sees the compliance schedule the assignment will follow.
const previewElements = computed(() =>
    form.requirement_id ? rqmtElements.listFor(form.requirement_id) : [],
);
const canDelete = computed(() => isEdit.value && props.target?.can_delete);

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
            form.description = props.target.description ?? '';
            form.start_date =
                props.target.start_date ??
                new Date().toISOString().slice(0, 10);
            form.end_date = props.target.end_date ?? '';
        } else {
            form.user_id = props.initialUserId ?? '';
            form.requirement_id = '';
            form.description = '';
            form.start_date = new Date().toISOString().slice(0, 10);
            form.end_date = '';
        }
    },
);

// Load the chosen requirement's elements for the preview, and default the
// description from it on create (without clobbering anything typed).
watch(
    () => form.requirement_id,
    async (id) => {
        if (!id) {
            return;
        }

        await rqmtElements.loadFor(id);

        if (!isEdit.value) {
            const r = requirements.library.find((x) => x.id === id);

            if (r && form.description.trim() === '') {
                form.description = r.description ?? '';
            }
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
            description:
                form.description.trim() === '' ? null : form.description,
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

const remove = async () => {
    if (!props.target) {
        return;
    }

    const who = userName(props.target.user_id);

    if (
        !window.confirm(
            `Delete the "${requirementName(props.target.requirement_id)}" assignment for ${who}?`,
        )
    ) {
        return;
    }

    deleting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.destroy(props.target.id);
        emit('update:open', false);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to delete assignment',
        });
    } finally {
        deleting.value = false;
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
                    <Select v-model="form.user_id" :disabled="userLocked">
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
                    <p
                        v-else-if="userLocked"
                        class="text-xs text-muted-foreground"
                    >
                        Adding for {{ userName(form.user_id) }}. Use “+ New
                        assignment” to pick a different user.
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
                    <Label for="a_desc">Description</Label>
                    <textarea
                        id="a_desc"
                        v-model="form.description"
                        rows="2"
                        class="w-full rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                    <InputError :message="fieldErrors.message('description')" />
                </div>

                <div
                    v-if="form.requirement_id"
                    class="grid gap-2 rounded-md border border-border bg-muted/40 p-3"
                >
                    <p class="text-xs font-medium">
                        Schedule (set on the requirement, not here)
                    </p>
                    <ul
                        v-if="previewElements.length > 0"
                        class="space-y-1 text-xs"
                    >
                        <li
                            v-for="el in previewElements"
                            :key="el.id"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="truncate">{{ el.name }}</span>
                            <span class="shrink-0 text-muted-foreground">
                                {{ elementTimingLabel(el) }}
                            </span>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">
                        This requirement has no elements yet — add trainings to
                        it from the requirement page.
                    </p>
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

                <DialogFooter class="sm:justify-between">
                    <Button
                        v-if="canDelete"
                        type="button"
                        variant="destructive"
                        :disabled="deleting || submitting"
                        @click="remove"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete' }}
                    </Button>
                    <span v-else />
                    <div class="flex gap-2">
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
                    </div>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
