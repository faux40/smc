<script setup lang="ts">
/*
 * Header notification bell with unread count badge. Subscribes the
 * notifications store to the per-user realtime channel on mount so
 * the badge lights up immediately when a new notification arrives.
 *
 * Clicking the bell navigates to /notifications. A future polish
 * could render a dropdown preview here; the dedicated inbox page
 * keeps 15.2 small.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import { page as notificationsPage } from '@/routes/notifications';
import { useNotificationsStore } from '@/stores/notifications';

const store = useNotificationsStore();
const page = usePage();

const authUserId = computed(() => {
    const u = page.props.auth.user as unknown as { id?: string } | null;

    return u?.id ?? null;
});

onMounted(async () => {
    if (authUserId.value) {
        store.subscribe(authUserId.value);
    }

    try {
        await store.load();
    } catch {
        // Bell still renders if the load fails — count just stays 0.
    }
});

const badgeLabel = computed(() => {
    if (store.unreadCount <= 0) {
        return null;
    }

    return store.unreadCount > 99 ? '99+' : String(store.unreadCount);
});
</script>

<template>
    <Link
        :href="notificationsPage()"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-full hover:bg-accent"
        aria-label="Notifications"
    >
        <Bell class="size-5 opacity-80" />
        <span
            v-if="badgeLabel"
            class="absolute -top-0.5 -right-0.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 py-0.5 text-[10px] leading-none font-medium text-white tabular-nums ring-2 ring-background"
        >
            {{ badgeLabel }}
        </span>
    </Link>
</template>
