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
    name: string;
    email: string;
    role: string;
    status: 'active' | 'disabled';
}

const ASSIGNABLE_ROLES = ['SuperAdmin', 'Admin', 'Manager', 'SelfEdit', 'SelfView', 'None'];

const form = reactive<FormState>({
    name: '',
    email: '',
    role: 'None',
    status: 'active',
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit user' : 'Add user'));
const submitLabel = computed(() => (isEdit.value ? 'Save' : 'Add user'));

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        errors.value = {};
        if (isEdit.value && props.target) {
            form.name = props.target.name;
            form.email = props.target.email ?? '';
            form.role = props.target.role ?? 'None';
            form.status = props.target.status;
        } else {
            form.name = '';
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

    if (isEdit.value && props.target) {
        store.update(
            props.target.id,
            {
                name: form.name,
                email: form.email.trim() === '' ? null : form.email,
                role: form.role,
                status: form.status,
            },
            opts,
        );
    } else {
        store.create(
            { name: form.name, email: form.email.trim() === '' ? null : form.email },
            opts,
        );
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

                <div class="grid gap-2">
                    <Label for="user_name">Name</Label>
                    <Input
                        id="user_name"
                        v-model="form.name"
                        required
                        autofocus
                    />
                    <InputError :message="errors.name" />
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
