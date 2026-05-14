<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import NotificationPreferencesController from '@/actions/App/Http/Controllers/Settings/NotificationPreferencesController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit } from '@/routes/notification-preferences';

type Channel = 'inapp' | 'mail';
type PreferenceMatrix = Record<string, Record<Channel, boolean>>;

type Props = {
    preferences: PreferenceMatrix;
    mailEnabled: boolean;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

// Each row is a notification type; columns are the two logical channels.
const TYPE_META: { key: string; label: string; description: string }[] = [
    {
        key: 'assignment_created',
        label: 'Assignment created',
        description: 'When a requirement is assigned to you.',
    },
    {
        key: 'completion_recorded',
        label: 'Completion recorded',
        description: 'When a completion is logged on your behalf.',
    },
    {
        key: 'assignment_due_soon',
        label: 'Assignment due soon',
        description: 'When one of your requirements is approaching its due date.',
    },
    {
        key: 'assignment_overdue',
        label: 'Assignment overdue',
        description: 'When one of your requirements becomes overdue.',
    },
    {
        key: 'manager_digest',
        label: 'Weekly manager digest',
        description: 'A Monday-morning compliance rollup for your organization.',
    },
];

// The manager digest only goes to Owner / SuperAdmin / Admin / Manager
// users, so the toggle is hidden for everyone else — their stored
// preference still rides along in the form payload, ready if they're
// ever promoted.
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth.user as {
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const isManagerPlus = computed(() => {
    const u = authUser.value;
    return !!u && !!(u.isOwner || u.isSuperAdmin || u.isAdmin || u.isManager);
});
const visibleTypes = computed(() =>
    TYPE_META.filter(
        (t) => t.key !== 'manager_digest' || isManagerPlus.value,
    ),
);

const form = useForm<{ preferences: PreferenceMatrix }>({
    preferences: JSON.parse(JSON.stringify(props.preferences)),
});

const submit = () => {
    form.patch(NotificationPreferencesController.update().url, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Notification preferences"
            description="Choose how you'd like to be notified. In-app covers the inbox and the realtime bell; email is sent separately."
        />

        <form @submit.prevent="submit" class="space-y-6">
            <div class="overflow-hidden rounded-lg border">
                <div
                    class="grid grid-cols-[1fr_5rem_5rem] items-center gap-2 border-b bg-muted/50 px-4 py-2 text-sm font-medium"
                >
                    <span>Notification</span>
                    <span class="text-center">In-app</span>
                    <span class="text-center">Email</span>
                </div>

                <div
                    v-for="type in visibleTypes"
                    :key="type.key"
                    class="grid grid-cols-[1fr_5rem_5rem] items-center gap-2 border-b px-4 py-3 last:border-b-0"
                >
                    <div>
                        <p class="text-sm font-medium">{{ type.label }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ type.description }}
                        </p>
                    </div>

                    <div class="flex justify-center">
                        <Checkbox
                            v-model="form.preferences[type.key].inapp"
                            :aria-label="`In-app notifications for ${type.label}`"
                        />
                    </div>

                    <div class="flex justify-center">
                        <Checkbox
                            v-model="form.preferences[type.key].mail"
                            :disabled="!props.mailEnabled"
                            :aria-label="`Email notifications for ${type.label}`"
                        />
                    </div>
                </div>
            </div>

            <p
                v-if="!props.mailEnabled"
                class="text-xs text-muted-foreground"
            >
                Email notifications are currently disabled for this
                deployment. In-app preferences still apply; email toggles
                will take effect once email is enabled.
            </p>

            <div class="flex items-center gap-4">
                <Button :disabled="form.processing">Save</Button>
            </div>
        </form>
    </div>
</template>
