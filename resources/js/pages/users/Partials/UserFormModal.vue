<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
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
import { useUsersStore } from '@/stores/users';

const props = defineProps<{ open: boolean; mode: 'create' }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useUsersStore();

interface FormState {
    name: string;
    email: string;
}

const form = reactive<FormState>({ name: '', email: '' });
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.name = '';
            form.email = '';
            errors.value = {};
        }
    },
);

const submit = () => {
    submitting.value = true;
    errors.value = {};

    store.create(
        { name: form.name, email: form.email.trim() === '' ? null : form.email },
        {
            onSuccess: () => {
                submitting.value = false;
                emit('update:open', false);
            },
            onError: (e) => {
                submitting.value = false;
                errors.value = e;
            },
        },
    );
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
                    <DialogTitle>Add user</DialogTitle>
                    <DialogDescription>
                        Adds a new member to your organization. Default role is
                        None until you assign one. Leave email blank for a
                        no-login user (e.g. frontline workers tracked for
                        compliance but not logging in).
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

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="submitting">
                        Add user
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
