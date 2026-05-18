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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUsersStore, type UserRow } from '@/stores/users';

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
}

const ASSIGNABLE_ROLES = ['SuperAdmin', 'Admin', 'Manager', 'SelfEdit', 'SelfView', 'None'];

const form = reactive<FormState>({
    f_name: '',
    m_name: '',
    l_name: '',
    prefix_name: '',
    suffix_name: '',
    email: '',
    role: 'None',
    status: 'active',
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const isOwnerTarget = computed(() => isEdit.value && props.target?.role === 'Owner');
const title = computed(() => (isEdit.value ? 'Edit user' : 'Add user'));
const submitLabel = computed(() => (isEdit.value ? 'Save' : 'Add user'));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errors.value = {};
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
        } else {
            form.f_name = '';
            form.m_name = '';
            form.l_name = '';
            form.prefix_name = '';
            form.suffix_name = '';
            form.email = '';
            form.role = 'None';
            form.status = 'active';
        }
    },
);

const submit = () => {
    submitting.value = true;
    errors.value = {};

    const opts = {
        onSuccess: () => {
            submitting.value = false;
            emit('update:open', false);
        },
        onError: (e: Record<string, string>) => {
            submitting.value = false;
            errors.value = e;
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

    if (isEdit.value && props.target) {
        // Owner role is managed by the (planned) ownership-transfer
        // flow, not this form. Omit `role` entirely so the backend
        // leaves the Owner's role intact.
        store.update(
            props.target.id,
            {
                ...namePayload,
                email,
                status: form.status,
                ...(isOwnerTarget.value ? {} : { role: form.role }),
            },
            opts,
        );
    } else {
        store.create({ ...namePayload, email }, opts);
    }
};
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(v) => emit('update:open', v)"
    >
        <DialogContent>
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="isEdit">
                            Update this user's profile, role, and status.
                            Owner role is reserved for the ownership-transfer
                            flow (coming later).
                        </template>
                        <template v-else>
                            Adds a new member to your organization. Default
                            role is None until you assign one. Leave email
                            blank for a no-login user.
                        </template>
                    </DialogDescription>
                </DialogHeader>

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
                        <InputError :message="errors.f_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_l_name">Last name</Label>
                        <Input
                            id="user_l_name"
                            v-model="form.l_name"
                            required
                            autocomplete="family-name"
                        />
                        <InputError :message="errors.l_name" />
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
                        <InputError :message="errors.prefix_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_m_name">Middle</Label>
                        <Input
                            id="user_m_name"
                            v-model="form.m_name"
                            autocomplete="additional-name"
                        />
                        <InputError :message="errors.m_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="user_suffix_name">Suffix</Label>
                        <Input
                            id="user_suffix_name"
                            v-model="form.suffix_name"
                            placeholder="Jr."
                        />
                        <InputError :message="errors.suffix_name" />
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
                    <InputError :message="errors.email" />
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
                                    Reassigned via the ownership-transfer flow (coming later).
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
                            <InputError :message="errors.role" />
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
                                <SelectItem value="disabled">disabled</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.status" />
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
