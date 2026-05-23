<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
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
import { useErrorStore } from '@/stores/errors';
import { useUsersStore } from '@/stores/users';
import type { UserRow } from '@/stores/users';

const FORM_CTX = 'form:user';

type Mode = 'create' | 'edit';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: UserRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useUsersStore();

interface FormState {
    f_name: string;
    m_name: string;
    l_name: string;
    prefix_name: string;
    suffix_name: string;
    email: string;
    role: string;
    status: 'active' | 'disabled';
    department: string;
    location: string;
    job_title: string;
    employee_number: string;
    supervisor_id: string; // '' = none
    start_date: string;
    end_date: string;
}

// Sentinel for the "no supervisor" Select option (reka-ui rejects empty-string
// item values); mapped back to '' in the form.
const NO_SUPERVISOR = '__none';

const ASSIGNABLE_ROLES = [
    'SuperAdmin',
    'Admin',
    'Manager',
    'SelfEdit',
    'SelfView',
    'None',
];

const form = reactive<FormState>({
    f_name: '',
    m_name: '',
    l_name: '',
    prefix_name: '',
    suffix_name: '',
    email: '',
    role: 'None',
    status: 'active',
    department: '',
    location: '',
    job_title: '',
    employee_number: '',
    supervisor_id: '',
    start_date: '',
    end_date: '',
});

// Same-org users eligible as a supervisor — exclude the user being edited so
// nobody can supervise themselves (the backend also rejects it).
const supervisorOptions = computed(() =>
    store.users.filter((u) => u.id !== props.target?.id),
);

// reka-ui Select can't bind the empty string, so proxy '' ↔ the sentinel.
const supervisorModel = computed({
    get: () => (form.supervisor_id === '' ? NO_SUPERVISOR : form.supervisor_id),
    set: (v: string) => {
        form.supervisor_id = v === NO_SUPERVISOR ? '' : v;
    },
});
const submitting = ref(false);
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const isEdit = computed(() => props.mode === 'edit');
const isOwnerTarget = computed(
    () => isEdit.value && props.target?.role === 'Owner',
);
const title = computed(() => (isEdit.value ? 'Edit user' : 'Add user'));
const submitLabel = computed(() => (isEdit.value ? 'Save' : 'Add user'));

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errorStore.clear(FORM_CTX);

        if (isEdit.value && props.target) {
            const t = props.target as UserRow & {
                f_name?: string;
                m_name?: string | null;
                l_name?: string;
                prefix_name?: string | null;
                suffix_name?: string | null;
            };
            form.f_name = t.f_name ?? '';
            form.m_name = t.m_name ?? '';
            form.l_name = t.l_name ?? '';
            form.prefix_name = t.prefix_name ?? '';
            form.suffix_name = t.suffix_name ?? '';
            form.email = t.email ?? '';
            form.role = t.role ?? 'None';
            form.status = t.status;
            form.department = t.department ?? '';
            form.location = t.location ?? '';
            form.job_title = t.job_title ?? '';
            form.employee_number = t.employee_number ?? '';
            form.supervisor_id = t.supervisor_id ?? '';
            form.start_date = t.start_date ?? '';
            form.end_date = t.end_date ?? '';
        } else {
            form.f_name = '';
            form.m_name = '';
            form.l_name = '';
            form.prefix_name = '';
            form.suffix_name = '';
            form.email = '';
            form.role = 'None';
            form.status = 'active';
            form.department = '';
            form.location = '';
            form.job_title = '';
            form.employee_number = '';
            form.supervisor_id = '';
            form.start_date = '';
            form.end_date = '';
        }
    },
);

const submit = () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);

    const opts = {
        onSuccess: () => {
            submitting.value = false;
            emit('update:open', false);
        },
        onError: (e: Record<string, string>) => {
            // Inertia router hands us a flat `field -> message` map for
            // 422 validation. Lift it into the cross-cutting error
            // store as field errors (no banner — the per-input
            // InputError below the field surfaces each entry).
            submitting.value = false;
            errorStore.report({
                context: FORM_CTX,
                message: 'Validation failed',
                fieldErrors: Object.fromEntries(
                    Object.entries(e).map(([k, v]) => [k, [v]]),
                ),
                surface: 'field',
            });
        },
    };

    const namePayload = {
        f_name: form.f_name,
        m_name: form.m_name.trim() === '' ? null : form.m_name,
        l_name: form.l_name,
        prefix_name: form.prefix_name.trim() === '' ? null : form.prefix_name,
        suffix_name: form.suffix_name.trim() === '' ? null : form.suffix_name,
    };
    const email = form.email.trim() === '' ? null : form.email;

    const blank = (v: string) => (v.trim() === '' ? null : v);
    const profilePayload = {
        department: blank(form.department),
        location: blank(form.location),
        job_title: blank(form.job_title),
        employee_number: blank(form.employee_number),
        supervisor_id: form.supervisor_id === '' ? null : form.supervisor_id,
        start_date: blank(form.start_date),
        end_date: blank(form.end_date),
    };

    if (isEdit.value && props.target) {
        // Owner role is managed by the (planned) ownership-transfer
        // flow, not this form. Omit `role` entirely so the backend
        // leaves the Owner's role intact.
        store.update(
            props.target.id,
            {
                ...namePayload,
                ...profilePayload,
                email,
                status: form.status,
                ...(isOwnerTarget.value ? {} : { role: form.role }),
            },
            opts,
        );
    } else {
        store.create({ ...namePayload, ...profilePayload, email }, opts);
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent>
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="isEdit">
                            Update this user's profile, role, and status. Owner
                            role is reserved for the ownership-transfer flow
                            (coming later).
                        </template>
                        <template v-else>
                            Adds a new member to your organization. Default role
                            is None until you assign one. Leave email blank for
                            a no-login user.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <ErrorBanner :context="FORM_CTX" />

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="user_f_name">First name</Label>
                        <Input
                            id="user_f_name"
                            v-model="form.f_name"
                            required
                            autofocus
                            autocomplete="given-name"
                        />
                        <InputError :message="fieldErrors.message('f_name')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_l_name">Last name</Label>
                        <Input
                            id="user_l_name"
                            v-model="form.l_name"
                            required
                            autocomplete="family-name"
                        />
                        <InputError :message="fieldErrors.message('l_name')" />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="user_prefix_name">Prefix</Label>
                        <Input
                            id="user_prefix_name"
                            v-model="form.prefix_name"
                            placeholder="Dr."
                        />
                        <InputError
                            :message="fieldErrors.message('prefix_name')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_m_name">Middle</Label>
                        <Input
                            id="user_m_name"
                            v-model="form.m_name"
                            autocomplete="additional-name"
                        />
                        <InputError :message="fieldErrors.message('m_name')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_suffix_name">Suffix</Label>
                        <Input
                            id="user_suffix_name"
                            v-model="form.suffix_name"
                            placeholder="Jr."
                        />
                        <InputError
                            :message="fieldErrors.message('suffix_name')"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="user_email">Email (optional)</Label>
                    <Input
                        id="user_email"
                        type="email"
                        v-model="form.email"
                        placeholder="leave blank for no-login user"
                    />
                    <InputError :message="fieldErrors.message('email')" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="user_department">Department</Label>
                        <Input id="user_department" v-model="form.department" />
                        <InputError
                            :message="fieldErrors.message('department')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_location">Location</Label>
                        <Input id="user_location" v-model="form.location" />
                        <InputError
                            :message="fieldErrors.message('location')"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="user_job_title">Job title</Label>
                        <Input id="user_job_title" v-model="form.job_title" />
                        <InputError
                            :message="fieldErrors.message('job_title')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_emp_num">Employee #</Label>
                        <Input
                            id="user_emp_num"
                            v-model="form.employee_number"
                        />
                        <InputError
                            :message="fieldErrors.message('employee_number')"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="user_supervisor">Supervisor (optional)</Label>
                    <Select v-model="supervisorModel">
                        <SelectTrigger id="user_supervisor">
                            <SelectValue placeholder="No supervisor" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_SUPERVISOR"
                                >No supervisor</SelectItem
                            >
                            <SelectItem
                                v-for="u in supervisorOptions"
                                :key="u.id"
                                :value="u.id"
                            >
                                {{ u.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError
                        :message="fieldErrors.message('supervisor_id')"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="user_start_date">Start date</Label>
                        <Input
                            id="user_start_date"
                            type="date"
                            v-model="form.start_date"
                        />
                        <InputError
                            :message="fieldErrors.message('start_date')"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_end_date">End date</Label>
                        <Input
                            id="user_end_date"
                            type="date"
                            v-model="form.end_date"
                        />
                        <InputError
                            :message="fieldErrors.message('end_date')"
                        />
                    </div>
                </div>

                <template v-if="isEdit">
                    <div class="grid gap-2">
                        <Label for="user_role">Role</Label>
                        <template v-if="isOwnerTarget">
                            <div
                                id="user_role"
                                class="flex items-center gap-2 rounded-md border border-input bg-muted/40 px-3 py-2 text-sm"
                            >
                                <span class="font-medium">Owner</span>
                                <span class="text-xs text-muted-foreground">
                                    Reassigned via the ownership-transfer flow
                                    (coming later).
                                </span>
                            </div>
                        </template>
                        <template v-else>
                            <Select v-model="form.role">
                                <SelectTrigger id="user_role">
                                    <SelectValue placeholder="Pick a role" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="r in ASSIGNABLE_ROLES"
                                        :key="r"
                                        :value="r"
                                    >
                                        {{ r }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="fieldErrors.message('role')"
                            />
                        </template>
                    </div>

                    <div class="grid gap-2">
                        <Label for="user_status">Status</Label>
                        <Select v-model="form.status">
                            <SelectTrigger id="user_status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="active">active</SelectItem>
                                <SelectItem value="disabled"
                                    >disabled</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="fieldErrors.message('status')" />
                    </div>
                </template>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        {{ submitLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
