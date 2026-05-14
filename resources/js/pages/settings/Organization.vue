<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { edit } from '@/routes/organization';

type Props = {
    organization: { id: string; name: string; timezone: string };
    isOwner: boolean;
    timezones: string[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Organization settings',
                href: edit(),
            },
        ],
    },
});

const deleteOpen = ref(false);
const deleteForm = useForm({ password: '' });

const submitDelete = () => {
    deleteForm.delete(OrganizationController.destroy().url, {
        preserveScroll: true,
        onError: () => undefined,
    });
};
</script>

<template>
    <Head title="Organization settings" />

    <h1 class="sr-only">Organization settings</h1>

    <div class="flex flex-col space-y-8">
        <Heading
            variant="small"
            title="Organization information"
            description="Update your organization's display name."
        />

        <Form
            v-bind="OrganizationController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    class="mt-1 block w-full"
                    :default-value="props.organization.name"
                    required
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <select
                    id="timezone"
                    name="timezone"
                    class="border-input bg-background mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] focus-visible:outline-none"
                    required
                >
                    <option
                        v-for="tz in props.timezones"
                        :key="tz"
                        :value="tz"
                        :selected="tz === props.organization.timezone"
                    >
                        {{ tz }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    Used to schedule the weekly compliance digest at 8am
                    local time on Mondays.
                </p>
                <InputError class="mt-2" :message="errors.timezone" />
            </div>
            <div class="flex items-center gap-4">
                <Button :disabled="processing">Save</Button>
            </div>
        </Form>

        <div v-if="isOwner" class="space-y-3">
            <Heading
                variant="small"
                title="Delete organization"
                description="Permanently soft-delete this organization. All users in the org are deactivated and cannot log in."
            />
            <Dialog v-model:open="deleteOpen">
                <DialogTrigger as-child>
                    <Button variant="destructive">
                        Delete organization
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <form @submit.prevent="submitDelete" class="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                Delete {{ organization.name }}?
                            </DialogTitle>
                            <DialogDescription>
                                Confirm your password to continue. This will
                                soft-delete every user in the organization and
                                log you out.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="grid gap-2">
                            <Label for="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                v-model="deleteForm.password"
                                required
                            />
                            <InputError :message="deleteForm.errors.password" />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                @click="deleteOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="deleteForm.processing"
                            >
                                Delete organization
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
