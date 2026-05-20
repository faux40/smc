<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="f_name">First name</Label>
                    <Input
                        id="f_name"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="given-name"
                        name="f_name"
                        placeholder="First"
                    />
                    <InputError
                        :message="(errors as Record<string, string>).f_name"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="l_name">Last name</Label>
                    <Input
                        id="l_name"
                        type="text"
                        required
                        :tabindex="2"
                        autocomplete="family-name"
                        name="l_name"
                        placeholder="Last"
                    />
                    <InputError
                        :message="(errors as Record<string, string>).l_name"
                    />
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="grid gap-2">
                    <Label for="prefix_name">Prefix</Label>
                    <Input
                        id="prefix_name"
                        type="text"
                        :tabindex="3"
                        name="prefix_name"
                        placeholder="Dr."
                    />
                    <InputError
                        :message="
                            (errors as Record<string, string>).prefix_name
                        "
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="m_name">Middle</Label>
                    <Input
                        id="m_name"
                        type="text"
                        :tabindex="4"
                        autocomplete="additional-name"
                        name="m_name"
                        placeholder="Middle"
                    />
                    <InputError
                        :message="(errors as Record<string, string>).m_name"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="suffix_name">Suffix</Label>
                    <Input
                        id="suffix_name"
                        type="text"
                        :tabindex="5"
                        name="suffix_name"
                        placeholder="Jr."
                    />
                    <InputError
                        :message="
                            (errors as Record<string, string>).suffix_name
                        "
                    />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="org_name">Organization name</Label>
                <Input
                    id="org_name"
                    type="text"
                    required
                    :tabindex="6"
                    name="org_name"
                    placeholder="Your company or team"
                />
                <InputError
                    :message="(errors as Record<string, string>).org_name"
                />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="7"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="8"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="9"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="10"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>

        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="login()"
                class="underline underline-offset-4"
                :tabindex="11"
                >Log in</TextLink
            >
        </div>
    </Form>
</template>
