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
import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';
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
    // Sortable, last-name-first display name ("Lovelace, Ada Augusta"),
    // composed once on the backend. Lists and pickers render this.
    sort_name: string;
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
    supervisor_name: string | null;
    supervisor_sort_name: string | null;
    start_date: string | null;
    end_date: string | null;
    notes: string | null;
    created_at: string | null;
    // Tag IDs attached to this user. Used to hydrate the tagsStore
    // `attached` map on first paint so TagsListCell renders without a
    // follow-up fetch.
    tag_ids: string[];
    can_edit: boolean;
    can_disable: boolean;
    can_delete: boolean;
}

export interface FieldOptions {
    department: string[];
    location: string[];
    job_title: string[];
}

/**
 * Lean row returned by the picker endpoint (GET /api/users) and by the
 * inline JSON create path (POST /users with Accept: json) — the subset of
 * UserRow those endpoints serialize.
 */
export type PickerUserRow = Pick<
    UserRow,
    | 'id'
    | 'name'
    | 'sort_name'
    | 'f_name'
    | 'm_name'
    | 'l_name'
    | 'email'
    | 'employee_number'
    | 'department'
    | 'location'
    | 'job_title'
    | 'supervisor_id'
    | 'supervisor_name'
    | 'supervisor_sort_name'
    | 'tag_ids'
    | 'status'
>;

/** One row submitted by the BULK USER ADD grid. */
export interface BulkUserRow {
    f_name: string;
    m_name?: string | null;
    l_name: string;
    email?: string | null;
    role?: string;
    department?: string | null;
    location?: string | null;
    job_title?: string | null;
    employee_number?: string | null;
    supervisor_id?: string | null;
    start_date?: string | null;
    end_date?: string | null;
}

export interface BulkRowResult {
    index: number;
    status: 'created' | 'skipped';
    user_id?: string;
    errors?: Record<string, string[]>;
}

export interface BulkCreateResponse {
    created: number;
    skipped: number;
    results: BulkRowResult[];
}

/** CSRF + origin-tab headers for axios writes to web routes (mirrors classes store). */
function writeHeaders(): Record<string, string> {
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

/** One profile field in the combine-users diff. */
export interface MergeFieldRow {
    key: string;
    label: string;
    survivor: string | null;
    duplicate: string | null;
    differs: boolean;
    default: 'survivor' | 'duplicate';
}

export interface MergePreview {
    survivor: { id: string; name: string; email: string | null };
    duplicate: { id: string; name: string; email: string | null };
    fields: MergeFieldRow[];
    role: { survivor: string | null; duplicate: string | null };
    counts: Record<string, number>;
}

interface BroadcastUser {
    id: string;
    name?: string;
    sort_name?: string;
    f_name?: string;
    m_name?: string | null;
    l_name?: string;
    prefix_name?: string | null;
    suffix_name?: string | null;
    email?: string | null;
    status?: 'active' | 'disabled';
}

/**
 * Query for the server-paged users table: the generic ServerTableQuery plus the
 * users-specific filters (role / disabled / tags). The Index closure merges its
 * live filter state onto each useServerTable fetch.
 */
export type UsersListQuery = ServerTableQuery & {
    role?: string;
    include_disabled?: boolean;
    tags?: string[];
    tags_mode?: string;
};

export const useUsersStore = defineStore('users', () => {
    const users = ref<UserRow[]>([]);
    const subscribedOrgId = ref<string | null>(null);

    // Bumped on any user mutation (local or peer broadcast). The Index watches
    // it and re-pulls the current page, so the table stays consistent with the
    // server's sort/filter/paging instead of being patched row-by-row.
    const revision = ref(0);

    /**
     * Server-paged fetch for the users table ({data, meta} contract). The
     * useServerTable params carry page/per_page/sort/dir/q; the Index merges the
     * extra filter state (role / disabled / tags) onto them. Does not touch the
     * `users` picker cache.
     */
    async function fetchPage(
        params: UsersListQuery,
    ): Promise<ServerTableResponse<UserRow>> {
        const query: Record<string, string | number | string[]> = {
            page: params.page,
            per_page: params.per_page,
            dir: params.dir,
        };

        if (params.sort) {
            query.sort = params.sort;
        }
        if (params.q) {
            query.q = params.q;
        }
        if (params.role) {
            query.role = params.role;
        }
        if (params.include_disabled) {
            query.include_disabled = 1;
        }
        if (params.tags && params.tags.length > 0) {
            query.tags = params.tags;
            query.tags_mode = params.tags_mode ?? 'and';
        }

        const { data } = await axios.get<ServerTableResponse<UserRow>>(
            '/api/users/list',
            { headers: writeHeaders(), params: query },
        );

        return data;
    }

    // Distinct existing values for the free-text profile fields, cached so the
    // user-form type-ahead doesn't refetch on every open. One round trip per
    // session unless forced (e.g. after adding a brand-new value).
    const fieldOptions = ref<FieldOptions>({
        department: [],
        location: [],
        job_title: [],
    });
    const fieldOptionsLoaded = ref(false);

    async function loadFieldOptions(force = false): Promise<void> {
        if (fieldOptionsLoaded.value && !force) {
            return;
        }

        const { data } = await axios.get<FieldOptions>(
            '/api/users/field-options',
        );
        fieldOptions.value = data;
        fieldOptionsLoaded.value = true;
    }

    // Any user add/update may introduce a new department/location/job_title,
    // so drop the cache; the next form open refetches fresh distinct values
    // (no page refresh needed). Lazy on purpose — we only pay for the refetch
    // when the form is actually reopened.
    function invalidateFieldOptions(): void {
        fieldOptionsLoaded.value = false;
    }

    function hydrate(initial: UserRow[]) {
        users.value = [...initial];
    }

    /**
     * Lazily fill the cache from the picker endpoint (GET /api/users) for
     * pages that aren't the users Index and so never received the full list
     * via Inertia hydrate — e.g. the user-detail edit modal needs the org
     * roster to populate its supervisor dropdown. No-op if the cache is
     * already populated (navigating in from the Index keeps its richer rows),
     * unless `force` is set.
     *
     * `includeDisabled` requests the fuller active+disabled pool (e.g. the
     * class roster, which lets a manager enroll a disabled/inactive person
     * for historical record-keeping). Pass `force: true` alongside it if the
     * cache may already hold the active-only default — otherwise a prior
     * page's plain loadPicker() call wins and disabled rows never arrive.
     */
    async function loadPicker(
        force = false,
        includeDisabled = false,
    ): Promise<void> {
        if (users.value.length > 0 && !force) {
            return;
        }

        const { data } = await axios.get<PickerUserRow[]>('/api/users', {
            headers: writeHeaders(),
            params: includeDisabled ? { include_disabled: 1 } : undefined,
        });

        // Picker rows carry only a subset of UserRow; pad the rest with
        // defaults. These rows back the supervisor dropdown (id + name), not
        // an edit target, so the missing profile fields are immaterial.
        users.value = data.map((u) => ({
            id: u.id,
            name: u.name,
            sort_name: u.sort_name ?? '',
            f_name: u.f_name ?? '',
            m_name: u.m_name ?? null,
            l_name: u.l_name ?? '',
            prefix_name: null,
            suffix_name: null,
            email: u.email ?? null,
            status: u.status ?? 'active',
            role: null,
            department: u.department ?? null,
            location: u.location ?? null,
            job_title: u.job_title ?? null,
            employee_number: u.employee_number ?? null,
            supervisor_id: u.supervisor_id ?? null,
            supervisor_name: u.supervisor_name ?? null,
            supervisor_sort_name: u.supervisor_sort_name ?? null,
            start_date: null,
            end_date: null,
            notes: null,
            created_at: null,
            tag_ids: u.tag_ids ?? [],
            can_edit: false,
            can_disable: false,
            can_delete: false,
        }));
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

        // Each broadcast keeps the picker cache fresh (apply*) AND bumps the
        // revision so the paged Index re-pulls its current page.
        const onChange = <T>(fn: (p: T) => void) => (payload: T) => {
            fn(payload);
            revision.value++;
        };

        bind('UserRegistered', onChange((p: BroadcastUser) => applyAdded(p)));
        bind('UserUpdated', onChange((p: BroadcastUser) => applyUpdated(p)));
        bind('UserStatusChanged', onChange((p: BroadcastUser) => applyUpdated(p)));
        bind('UserSoftDeleted', onChange((p: BroadcastUser) => applySoftDeleted(p.id)));
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
                sort_name: payload.sort_name ?? '',
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
                supervisor_name: null,
                supervisor_sort_name: null,
                start_date: null,
                end_date: null,
                notes: null,
                created_at: null,
                // Realtime-created rows arrive without tag attachments; the
                // tagsStore reconciles via TagAttached broadcasts.
                tag_ids: [],
                can_edit: false,
                can_disable: false,
                can_delete: false,
            },
        ];

        invalidateFieldOptions();
    }

    function applyUpdated(payload: BroadcastUser) {
        users.value = users.value.map((u) =>
            u.id === payload.id
                ? {
                      ...u,
                      name: payload.name ?? u.name,
                      sort_name: payload.sort_name ?? u.sort_name,
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

        invalidateFieldOptions();
    }

    function applySoftDeleted(id: string) {
        users.value = users.value.filter((u) => u.id !== id);
    }

    const count = computed(() => users.value.length);

    // O(1) id lookup so list pages resolving many rows don't go quadratic.
    const usersById = computed(() => {
        const map = new Map<string, UserRow>();
        for (const u of users.value) {
            map.set(u.id, u);
        }
        return map;
    });

    function byId(id: string): UserRow | undefined {
        return usersById.value.get(id);
    }

    /**
     * Canonical display name for a user id — the single way components render
     * a user's name. Prefers the backend-composed sortable (last-name-first)
     * name, then the natural name, then email, then empty.
     */
    function displayName(id: string): string {
        const u = usersById.value.get(id);
        if (!u) {
            return '';
        }
        return u.sort_name || u.name || u.email || '';
    }

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
        notes?: string | null;
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
                // Keep the table's page/sort/filter state; the revision bump
                // (below) re-pulls the current page rather than remounting.
                preserveState: true,
                onSuccess: () => {
                    // The new user may carry a fresh department/location/
                    // job_title — refresh the type-ahead options next open.
                    invalidateFieldOptions();
                    revision.value++;
                    opts.onSuccess?.();
                },
                onError: (errors) =>
                    opts.onError?.(errors as Record<string, string>),
            },
        );
    }

    /**
     * Non-navigating single create for inline callers (e.g. the class roster's
     * add-and-enroll flow). Unlike create() — which posts via Inertia and
     * redraws the users page — this posts JSON, patches the cache with the
     * returned row, and resolves to it so the caller can act on the new id.
     */
    async function createReturning(
        form: NamePayload & ProfilePayload & { email: string | null },
    ): Promise<PickerUserRow> {
        const { data } = await axios.post<PickerUserRow>(
            usersStore().url,
            form as unknown as Record<string, string>,
            { headers: writeHeaders() },
        );
        applyAdded(data);
        invalidateFieldOptions();
        revision.value++;

        return data;
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
                preserveState: true,
                onSuccess: () => {
                    invalidateFieldOptions();
                    revision.value++;
                    opts.onSuccess?.();
                },
                onError: (errors) =>
                    opts.onError?.(errors as Record<string, string>),
            },
        );
    }

    /**
     * BULK USER ADD — submit many rows to POST /users/bulk and return the
     * per-row report ({created, skipped, results}). Created users stream back
     * into the table via the org-channel UserRegistered sub (applyAdded), so
     * we only refresh the type-ahead options here.
     */
    async function bulkCreate(
        rows: BulkUserRow[],
    ): Promise<BulkCreateResponse> {
        const { data } = await axios.post<BulkCreateResponse>(
            '/users/bulk',
            { users: rows },
            { headers: writeHeaders() },
        );
        invalidateFieldOptions();
        revision.value++;

        return data;
    }

    /**
     * Combine-users preview: the side-by-side profile diff + record counts
     * the merge modal renders before the user commits.
     */
    async function mergePreview(
        survivorId: string,
        duplicateId: string,
    ): Promise<MergePreview> {
        const { data } = await axios.get<MergePreview>(
            '/api/users/merge-preview',
            {
                headers: writeHeaders(),
                params: { survivor: survivorId, duplicate: duplicateId },
            },
        );

        return data;
    }

    /**
     * Fold the duplicate into the survivor. On success patch the local cache
     * directly — the broadcast self-echo filter skips the originating tab, so
     * this tab won't get the UserUpdated/UserSoftDeleted it just triggered.
     */
    async function merge(payload: {
        survivor_id: string;
        duplicate_id: string;
        fields: Record<string, 'survivor' | 'duplicate'>;
    }): Promise<void> {
        const { data } = await axios.post<{
            survivor: BroadcastUser;
            duplicate_id: string;
        }>('/users/merge', payload, { headers: writeHeaders() });

        applySoftDeleted(data.duplicate_id);
        applyUpdated(data.survivor);
        invalidateFieldOptions();
        revision.value++;
    }

    // Quick row actions go through JSON (not Inertia) so the paged table updates
    // in place via the revision bump instead of a full-page redraw.
    async function disable(
        id: string,
        opts: { onSuccess?: () => void } = {},
    ): Promise<void> {
        await axios.post(usersDisable(id).url, {}, { headers: writeHeaders() });
        applyUpdated({ id, status: 'disabled' });
        revision.value++;
        opts.onSuccess?.();
    }

    async function enable(
        id: string,
        opts: { onSuccess?: () => void } = {},
    ): Promise<void> {
        await axios.post(usersEnable(id).url, {}, { headers: writeHeaders() });
        applyUpdated({ id, status: 'active' });
        revision.value++;
        opts.onSuccess?.();
    }

    async function destroy(
        id: string,
        opts: { onSuccess?: () => void } = {},
    ): Promise<void> {
        await axios.delete(usersDestroy(id).url, { headers: writeHeaders() });
        applySoftDeleted(id);
        revision.value++;
        opts.onSuccess?.();
    }

    return {
        users,
        revision,
        count,
        byId,
        displayName,
        fieldOptions,
        loadFieldOptions,
        fetchPage,
        hydrate,
        loadPicker,
        subscribe,
        applyAdded,
        applyUpdated,
        applySoftDeleted,
        create,
        createReturning,
        bulkCreate,
        update,
        mergePreview,
        merge,
        disable,
        enable,
        destroy,
    };
});
