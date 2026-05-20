<script setup lang="ts">
/*
 * Inbox page (Phase 15.2). Lists every notification (read + unread)
 * newest-first; click an unread row to flip it; one-click mark-all
 * clears every unread row.
 *
 * Row body renders are kind-aware: each notification's `kind` from
 * toArray() picks a short human label. New kinds added later just
 * extend the labelFor map — no per-page surgery.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { page as notificationsPage } from '@/routes/notifications';
import { show as userShow } from '@/routes/users';
import { useNotificationsStore } from '@/stores/notifications';
import type { NotificationRow } from '@/stores/notifications';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: notificationsPage() }],
    },
});

const store = useNotificationsStore();
const page = usePage();
const error = ref<string | null>(null);

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
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const LABELS: Record<string, string> = {
    assignment_created: 'New assignment',
    completion_recorded: 'Completion recorded',
};

const labelFor = (row: NotificationRow): string => {
    const kind = (row.data?.kind as string) ?? '';

    return LABELS[kind] ?? row.type.split('\\').pop() ?? 'Notification';
};

const summaryFor = (row: NotificationRow): string => {
    const kind = (row.data?.kind as string) ?? '';

    if (kind === 'assignment_created') {
        return (row.data?.name as string) ?? 'an assignment';
    }

    if (kind === 'completion_recorded') {
        const date = (row.data?.completion_date as string) ?? '';
        const count = Array.isArray(row.data?.rqmt_element_ids)
            ? (row.data.rqmt_element_ids as string[]).length
            : 0;

        return `${count} element(s) credited${date ? ` on ${date}` : ''}`;
    }

    return '';
};

const formatTs = (ts: string | null): string => {
    if (!ts) {
        return '';
    }

    return new Date(ts).toLocaleString();
};

const handleClick = async (row: NotificationRow): Promise<void> => {
    if (row.read_at !== null) {
        return;
    }

    try {
        await store.markRead(row.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
};

const markAllRead = async (): Promise<void> => {
    try {
        await store.markAllRead();
    } catch (e) {
        error.value = (e as Error).message;
    }
};

// Some notification kinds carry a user_id in payload (manager digests,
// future cases). When present, show a quick link back to that user.
const userLinkFor = (row: NotificationRow): string | null => {
    const uid = (row.data?.user_id as string) ?? null;

    return uid ? userShow(uid).url : null;
};
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Notifications"
                :description="`${store.unreadCount} unread of ${store.library.length} total.`"
            />
            <Button
                v-if="store.unreadCount > 0"
                type="button"
                variant="outline"
                @click="markAllRead"
            >
                Mark all read
            </Button>
        </div>

        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div
            v-if="store.library.length === 0"
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No notifications yet.
        </div>

        <ul
            v-else
            class="divide-y divide-border rounded-md border border-border"
        >
            <li
                v-for="row in store.library"
                :key="row.id"
                class="flex items-start gap-3 px-4 py-3 hover:bg-muted/40"
                :class="row.read_at === null ? 'bg-muted/20' : ''"
            >
                <span
                    class="mt-1 inline-block size-2 shrink-0 rounded-full"
                    :class="
                        row.read_at === null ? 'bg-primary' : 'bg-transparent'
                    "
                    :aria-label="row.read_at === null ? 'unread' : 'read'"
                />

                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline gap-2">
                        <span class="text-sm font-medium">{{
                            labelFor(row)
                        }}</span>
                        <Badge
                            v-if="row.read_at === null"
                            variant="secondary"
                            class="text-[10px]"
                        >
                            new
                        </Badge>
                        <span class="text-xs text-muted-foreground">
                            {{ formatTs(row.created_at) }}
                        </span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ summaryFor(row) }}
                    </p>
                    <Link
                        v-if="userLinkFor(row)"
                        :href="userLinkFor(row)!"
                        class="text-xs text-primary hover:underline"
                    >
                        View user
                    </Link>
                </div>

                <button
                    v-if="row.read_at === null"
                    type="button"
                    class="text-xs text-primary hover:underline"
                    @click="handleClick(row)"
                >
                    Mark read
                </button>
            </li>
        </ul>
    </div>
</template>
