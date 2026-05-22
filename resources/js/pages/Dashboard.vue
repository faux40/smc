<script setup lang="ts">
/*
 * Phase 14 dashboard page.
 *
 * Hosts the org compliance widgets. Today each widget is imported
 * statically and rendered in a fixed grid; the widgets themselves are
 * self-contained (own fetch, own loading/error state) so a future
 * user-prefs phase can swap this static layout for a dynamic registry
 * lookup without touching the widget components.
 *
 * Manager+ users see widgets; everyone else gets a friendly "head to
 * your own user detail page" hint (the data inside the widgets is
 * org-wide and the backend 403s non-managers anyway).
 */
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AllUsersComplianceWidget from '@/components/dashboard/AllUsersComplianceWidget.vue';
import DueSoonWidget from '@/components/dashboard/DueSoonWidget.vue';
import RecentCompletionsWidget from '@/components/dashboard/RecentCompletionsWidget.vue';
import SummaryStatsWidget from '@/components/dashboard/SummaryStatsWidget.vue';
import { dashboard } from '@/routes';
import { show as userShow } from '@/routes/users';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const page = usePage();
const authUser = computed(
    // The shared User type carries id: number from the starter kit; our
    // app stores users with UUID strings. Existing pages (users/Index.vue)
    // use the same `as unknown as { ... }` shim — replace when the shared
    // type is widened.
    () =>
        page.props.auth.user as unknown as {
            id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const canSeeWidgets = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-4 p-4">
        <template v-if="canSeeWidgets">
            <SummaryStatsWidget />

            <AllUsersComplianceWidget />

            <div class="grid gap-4 md:grid-cols-2">
                <DueSoonWidget />
                <RecentCompletionsWidget />
            </div>
        </template>

        <template v-else>
            <p
                class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
            >
                The org dashboard is for Manager-or-higher roles. Head to your
                <a
                    v-if="authUser?.id"
                    :href="userShow(authUser.id).url"
                    class="font-medium text-primary hover:underline"
                    >user detail page</a
                >
                for your own compliance posture.
            </p>
        </template>
    </div>
</template>
