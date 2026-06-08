<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import OrganizationController from '@/actions/App/Http/Controllers/Settings/OrganizationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/organization';

type Props = {
    organization: {
        id: string;
        name: string;
        timezone: string;
        due_soon_days: number | null;
        expiring_soon_days: number | null;
    };
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
                    class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
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
                    Used to schedule the weekly compliance digest at 8am local
                    time on Mondays.
                </p>
                <InputError class="mt-2" :message="errors.timezone" />
            </div>
            <div class="grid gap-2">
                <Label for="due_soon_days">
                    "Due soon" window (days)
                </Label>
                <Input
                    id="due_soon_days"
                    name="due_soon_days"
                    type="number"
                    min="1"
                    max="365"
                    class="mt-1 block w-40"
                    :default-value="props.organization.due_soon_days ?? ''"
                    :placeholder="String(60)"
                />
                <p class="text-xs text-muted-foreground">
                    Assignments expiring within this many days appear in the
                    "Due soon" dashboard list. Leave blank to use the default
                    (60 days).
                </p>
                <InputError class="mt-2" :message="errors.due_soon_days" />
            </div>
            <div class="grid gap-2">
                <Label for="expiring_soon_days">
                    "Expiring soon" pill threshold (days)
                </Label>
                <Input
                    id="expiring_soon_days"
                    name="expiring_soon_days"
                    type="number"
                    min="1"
                    max="365"
                    class="mt-1 block w-40"
                    :default-value="props.organization.expiring_soon_days ?? ''"
                    :placeholder="String(30)"
                />
                <p class="text-xs text-muted-foreground">
                    Assignment pills turn amber when expiry is within this
                    many days. Leave blank to use the default (30 days).
                </p>
                <InputError class="mt-2" :message="errors.expiring_soon_days" />
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
                    <Button variant="destructive"> Delete organization </Button>
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
