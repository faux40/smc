/*
 * Users store — the data relay between the org's users list and the UI.
 *
 * Per the Pinia-relay principle (smc_specs/claude_thoughts.md), components
 * never call fetch / axios / router.post for user data. They call store
 * methods. The store owns the in-memory cache, hydrates from Inertia
 * page props on first paint, and subscribes to the org channel so peer
 * mutations are applied without a manual reload.
 *
 * Mutation methods (create/update/disable/enable/destroy) land in 4.2+.
 * 4.1 is the read substrate.
 */

import { router } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import {
    destroy as usersDestroy,
    disable as usersDisable,
    enable as usersEnable,
    store as usersStore,
    update as usersUpdate,
} from '@/routes/users';

export interface UserRow {
    id: string;
    name: string;
    f_name: string;
    m_name: string | null;
    l_name: string;
    prefix_name: string | null;
    suffix_name: string | null;
    email: string | null;
    status: 'active' | 'disabled';
    role: string | null;
    department: string | null;
    location: string | null;
    job_title: string | null;
    employee_number: string | null;
    supervisor_id: string | null;
    start_date: string | null;
    end_date: string | null;
    created_at: string | null;
    // Tag IDs attached to this user. Used to hydrate the tagsStore
    // `attached` map on first paint so TagsListCell renders without a
    // follow-up fetch.
    tag_ids: string[];
    can_edit: boolean;
    can_disable: boolean;
    can_delete: boolean;
}

interface BroadcastUser {
    id: string;
    name?: string;
    f_name?: string;
    m_name?: string | null;
    l_name?: string;
    prefix_name?: string | null;
    suffix_name?: string | null;
    email?: string | null;
    status?: 'active' | 'disabled';
}

export const useUsersStore = defineStore('users', () => {
    const users = ref<UserRow[]>([]);
    const subscribedOrgId = ref<string | null>(null);

    function hydrate(initial: UserRow[]) {
        users.value = [...initial];
    }

    /**
     * Subscribe to `org.{orgId}` so peer tabs see UserRegistered /
     * UserUpdated / UserSoftDeleted broadcasts and patch the cache
     * accordingly. The composable's self-echo filter skips broadcasts
     * originated by this tab.
     */
    function subscribe(orgId: string) {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind('UserRegistered', (payload: BroadcastUser) => applyAdded(payload));
        bind('UserUpdated', (payload: BroadcastUser) => applyUpdated(payload));
        bind('UserStatusChanged', (payload: BroadcastUser) =>
            applyUpdated(payload),
        );
        bind('UserSoftDeleted', (payload: BroadcastUser) =>
            applySoftDeleted(payload.id),
        );
    }

    function applyAdded(payload: BroadcastUser) {
        if (users.value.some((u) => u.id === payload.id)) {
            return;
        }

        users.value = [
            ...users.value,
            {
                id: payload.id,
                name: payload.name ?? '',
                f_name: payload.f_name ?? '',
                m_name: payload.m_name ?? null,
                l_name: payload.l_name ?? '',
                prefix_name: payload.prefix_name ?? null,
                suffix_name: payload.suffix_name ?? null,
                email: payload.email ?? null,
                status: payload.status ?? 'active',
                role: null,
                department: null,
                location: null,
                job_title: null,
                employee_number: null,
                supervisor_id: null,
                start_date: null,
                end_date: null,
                created_at: null,
                // Realtime-created rows arrive without tag attachments; the
                // tagsStore reconciles via TagAttached broadcasts.
                tag_ids: [],
                can_edit: false,
                can_disable: false,
                can_delete: false,
            },
        ];
    }

    function applyUpdated(payload: BroadcastUser) {
        users.value = users.value.map((u) =>
            u.id === payload.id
                ? {
                      ...u,
                      name: payload.name ?? u.name,
                      f_name: payload.f_name ?? u.f_name,
                      m_name:
                          payload.m_name !== undefined
                              ? payload.m_name
                              : u.m_name,
                      l_name: payload.l_name ?? u.l_name,
                      prefix_name:
                          payload.prefix_name !== undefined
                              ? payload.prefix_name
                              : u.prefix_name,
                      suffix_name:
                          payload.suffix_name !== undefined
                              ? payload.suffix_name
                              : u.suffix_name,
                      email:
                          payload.email !== undefined ? payload.email : u.email,
                      status: payload.status ?? u.status,
                  }
                : u,
        );
    }

    function applySoftDeleted(id: string) {
        users.value = users.value.filter((u) => u.id !== id);
    }

    const count = computed(() => users.value.length);

    /**
     * Admin add-user. Routes through Inertia so the request carries CSRF and
     * the X-Origin-Tab header already wired in app.ts. The response is the
     * users/Index page redrawn; Inertia re-runs the page setup which calls
     * store.hydrate() — so no manual cache patch needed here. Peer tabs
     * receive UserRegistered on the org channel and call applyAdded.
     */
    interface NamePayload {
        f_name: string;
        m_name: string | null;
        l_name: string;
        prefix_name: string | null;
        suffix_name: string | null;
    }

    // Optional profile fields shared by create + update.
    interface ProfilePayload {
        department?: string | null;
        location?: string | null;
        job_title?: string | null;
        supervisor_id?: string | null;
        start_date?: string | null;
        end_date?: string | null;
    }

    function create(
        form: NamePayload & ProfilePayload & { email: string | null },
        opts: {
            onSuccess?: () => void;
            onError?: (errors: Record<string, string>) => void;
        } = {},
    ): void {
        router.post(
            usersStore().url,
            form as unknown as Record<string, string>,
            {
                preserveScroll: true,
                onSuccess: () => opts.onSuccess?.(),
                onError: (errors) =>
                    opts.onError?.(errors as Record<string, string>),
            },
        );
    }

    function update(
        id: string,
        form: NamePayload &
            ProfilePayload & {
                email: string | null;
                role?: string;
                status: 'active' | 'disabled';
            },
        opts: {
            onSuccess?: () => void;
            onError?: (errors: Record<string, string>) => void;
        } = {},
    ): void {
        router.patch(
            usersUpdate(id).url,
            form as unknown as Record<string, string>,
            {
                preserveScroll: true,
                onSuccess: () => opts.onSuccess?.(),
                onError: (errors) =>
                    opts.onError?.(errors as Record<string, string>),
            },
        );
    }

    function disable(id: string, opts: { onSuccess?: () => void } = {}): void {
        router.post(
            usersDisable(id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => opts.onSuccess?.(),
            },
        );
    }

    function enable(id: string, opts: { onSuccess?: () => void } = {}): void {
        router.post(
            usersEnable(id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => opts.onSuccess?.(),
            },
        );
    }

    function destroy(id: string, opts: { onSuccess?: () => void } = {}): void {
        router.delete(usersDestroy(id).url, {
            preserveScroll: true,
            onSuccess: () => opts.onSuccess?.(),
        });
    }

    return {
        users,
        count,
        hydrate,
        subscribe,
        applyAdded,
        applyUpdated,
        applySoftDeleted,
        create,
        update,
        disable,
        enable,
        destroy,
    };
});
