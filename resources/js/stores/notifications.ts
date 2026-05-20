/*
 * Per-user inbox store (Phase 15.2).
 *
 * Owns: library (newest-first row cache), unread count, and the
 * private-channel subscription that lights up the bell in real time.
 *
 * Realtime path uses Echo's .notification() helper directly rather
 * than going through useRealtime — Laravel broadcasts notifications
 * with a fixed event name (Illuminate\Notifications\Events\Broadcast-
 * NotificationCreated) and .notification() is the idiomatic catch-all.
 */
import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { realtimeTabId } from '@/echo';

export interface NotificationRow {
    id: string;
    // FQCN of the App\Notifications\* class (e.g.
    // "App\\Notifications\\AssignmentCreatedForYou").
    type: string;
    // Payload set by each notification's toArray(); the inbox renders
    // out of this without needing a follow-up fetch.
    data: Record<string, unknown>;
    read_at: string | null;
    created_at: string | null;
}

interface IndexResponse {
    unread_count: number;
    items: NotificationRow[];
}

interface BroadcastPayload {
    id: string;
    type: string;
    read_at: string | null;
    created_at?: string | null;
    // Laravel folds your toArray() output here.
    [key: string]: unknown;
}

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}

export const useNotificationsStore = defineStore('notifications', () => {
    const library = ref<NotificationRow[]>([]);
    const unreadCount = ref(0);
    const loaded = ref(false);
    const subscribedUserId = ref<string | null>(null);

    const unread = computed(() =>
        library.value.filter((n) => n.read_at === null),
    );

    async function load(): Promise<void> {
        const { data } = await axios.get<IndexResponse>('/api/notifications', {
            headers: defaultHeaders(),
        });
        library.value = data.items;
        unreadCount.value = data.unread_count;
        loaded.value = true;
    }

    async function markRead(id: string): Promise<void> {
        const target = library.value.find((n) => n.id === id);

        if (target && target.read_at !== null) {
            return;
        } // already read

        const { data } = await axios.post<{
            id: string;
            read_at: string | null;
        }>(`/api/notifications/${id}/read`, {}, { headers: defaultHeaders() });
        library.value = library.value.map((n) =>
            n.id === id ? { ...n, read_at: data.read_at } : n,
        );
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    }

    async function markAllRead(): Promise<void> {
        const now = new Date().toISOString();
        await axios.post(
            '/api/notifications/read-all',
            {},
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((n) =>
            n.read_at === null ? { ...n, read_at: now } : n,
        );
        unreadCount.value = 0;
    }

    /**
     * Subscribe to the user's private notification channel
     * (`private-App.Models.User.{id}` — Laravel's default for the
     * Notifiable trait). Idempotent per user-id.
     */
    function subscribe(userId: string): void {
        if (subscribedUserId.value === userId) {
            return;
        }

        subscribedUserId.value = userId;

        const echo = window.Echo;

        if (!echo) {
            return;
        }

        echo.private(`App.Models.User.${userId}`).notification(
            (payload: BroadcastPayload) => {
                // Laravel's broadcast envelope includes id + type
                // alongside the user's toArray() payload. Reshape into
                // a NotificationRow so the inbox renders consistently.
                const { id, type, read_at, created_at, ...rest } = payload;
                const row: NotificationRow = {
                    id,
                    type,
                    data: rest as Record<string, unknown>,
                    read_at: read_at ?? null,
                    created_at: created_at ?? new Date().toISOString(),
                };

                if (library.value.some((n) => n.id === row.id)) {
                    return;
                }

                library.value = [row, ...library.value];

                if (row.read_at === null) {
                    unreadCount.value = unreadCount.value + 1;
                }
            },
        );
    }

    return {
        library,
        unread,
        unreadCount,
        loaded,
        load,
        markRead,
        markAllRead,
        subscribe,
    };
});
