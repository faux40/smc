<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import AttachmentsList from '@/components/AttachmentsList.vue';
import CommentsList from '@/components/CommentsList.vue';
import TagsField from '@/components/TagsField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { edit as organizationEdit } from '@/routes/organization';
import { send } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    tagIds: string[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile information"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="f_name">First name</Label>
                    <Input
                        id="f_name"
                        class="mt-1 block w-full"
                        name="f_name"
                        :default-value="(user as any).f_name"
                        required
                        autocomplete="given-name"
                    />
                    <InputError class="mt-2" :message="(errors as any).f_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="l_name">Last name</Label>
                    <Input
                        id="l_name"
                        class="mt-1 block w-full"
                        name="l_name"
                        :default-value="(user as any).l_name"
                        required
                        autocomplete="family-name"
                    />
                    <InputError class="mt-2" :message="(errors as any).l_name" />
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="grid gap-2">
                    <Label for="prefix_name">Prefix</Label>
                    <Input
                        id="prefix_name"
                        class="mt-1 block w-full"
                        name="prefix_name"
                        :default-value="(user as any).prefix_name"
                    />
                    <InputError class="mt-2" :message="(errors as any).prefix_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="m_name">Middle</Label>
                    <Input
                        id="m_name"
                        class="mt-1 block w-full"
                        name="m_name"
                        :default-value="(user as any).m_name"
                        autocomplete="additional-name"
                    />
                    <InputError class="mt-2" :message="(errors as any).m_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="suffix_name">Suffix</Label>
                    <Input
                        id="suffix_name"
                        class="mt-1 block w-full"
                        name="suffix_name"
                        :default-value="(user as any).suffix_name"
                    />
                    <InputError class="mt-2" :message="(errors as any).suffix_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to resend the verification email.
                    </Link>
                </p>

                <div
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <div class="space-y-3">
        <Heading
            variant="small"
            title="Tags"
            description="Apply tags to yourself for grouping and filtering."
        />
        <TagsField
            morphable-type="App\Models\User"
            :morphable-id="user.id"
            :initial-tag-ids="props.tagIds"
            :can-manage-library="
                Boolean(
                    (user as any).isOwner ||
                        (user as any).isSuperAdmin ||
                        (user as any).isAdmin,
                )
            "
        />
    </div>

    <div class="space-y-3">
        <Heading
            variant="small"
            title="Comments"
            description="Notes on your profile."
        />
        <CommentsList
            morphable-type="App\Models\User"
            :morphable-id="user.id"
        />
    </div>

    <div class="space-y-3">
        <Heading
            variant="small"
            title="Attachments"
            description="Files attached to your profile."
        />
        <AttachmentsList
            morphable-type="App\Models\User"
            :morphable-id="user.id"
        />
    </div>

    <div v-if="(user as any).isOwner" class="space-y-3">
        <Heading
            variant="small"
            title="Delete account"
            description="Owners cannot delete their own account."
        />
        <p class="text-sm text-muted-foreground">
            Transfer ownership (coming later) or delete the entire organization
            from
            <Link :href="organizationEdit().url" class="underline">
                organization settings
            </Link>
            instead.
        </p>
    </div>
    <DeleteUser v-else />
</template>
