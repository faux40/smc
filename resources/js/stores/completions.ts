/*
 * Completions store — records that a user satisfied one or more
 * rqmt_elements (and therefore one or more Requirements) on a date.
 *
 * The table is server-paged: the Index drives `fetchPage()` through
 * useServerTable and renders the returned page directly (no client cache).
 * Mutations (create/update/destroy) round-trip to the API; realtime
 * broadcasts just bump `revision` so the open page refetches itself.
 * Pivot links to rqmt_elements ride in `rqmt_element_ids`, synced
 * server-side on every create/update.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { realtimeTabId } from '@/echo';

export interface CompletionRow {
    id: string;
    user_id: string;
    module_type: string;
    module_id: string;
    training_name: string | null;
    completion_date: string | null;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    cert_id: string | null;
    hours: number | null;
    class_training_id: string | null;
    class_id: string | null;
    class_name: string | null;
    notes: string | null;
    rqmt_element_ids: string[];
    /** Pivot links ∪ module-identity matches — what the credit really satisfies. */
    effective_element_ids: string[];
    can_edit: boolean;
    can_delete: boolean;
}

export interface CompletionCreatePayload {
    user_id: string;
    module_type: string;
    module_id: string;
    completion_date: string;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    hours: number | null;
    notes: string | null;
    rqmt_element_ids: string[];
}

export type CompletionUpdatePayload = Omit<
    CompletionCreatePayload,
    'user_id' | 'module_type' | 'module_id'
>;

/**
 * F8 — record one training for many users at once. Carries a flat
 * `training_id` + `user_ids[]` (module_type is always Training server-side)
 * plus the shared completion fields.
 */
export interface CompletionBulkCreatePayload {
    user_ids: string[];
    training_id: string;
    completion_date: string;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    hours: number | null;
    notes: string | null;
    rqmt_element_ids: string[];
}

export interface CompletionBulkResult {
    created_count: number;
    skipped_count: number;
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

export const useCompletionsStore = defineStore('completions', () => {
    const subscribedOrgId = ref<string | null>(null);
    // Bumped on every completion broadcast — the paged Index watches it and
    // refetches its current page (we can't patch a server-paged set).
    const revision = ref(0);

    /**
     * Server-paged fetch for the completions table (the paged {data, meta}
     * contract). Does NOT touch the cache rows — the Index drives it through
     * useServerTable and renders the returned page directly.
     */
    async function fetchPage(
        params: ServerTableQuery & { user_id?: string },
    ): Promise<ServerTableResponse<CompletionRow>> {
        const query: Record<string, string | number> = {
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

        if (params.user_id) {
            query.user_id = params.user_id;
        }

        const { data } = await axios.get<ServerTableResponse<CompletionRow>>(
            '/api/completions',
            { headers: defaultHeaders(), params: query },
        );

        return data;
    }

    async function create(
        payload: CompletionCreatePayload,
    ): Promise<CompletionRow> {
        const { data } = await axios.post<CompletionRow>(
            '/api/completions',
            payload,
            { headers: defaultHeaders() },
        );

        return data;
    }

    /**
     * Record one training for many users in a single request. Returns the
     * created/skipped tallies (non-org / inactive users are skipped); the
     * server fires one CompletionsBulkChanged broadcast so open Index tabs
     * refetch.
     */
    async function bulkCreate(
        payload: CompletionBulkCreatePayload,
    ): Promise<CompletionBulkResult> {
        const { data } = await axios.post<CompletionBulkResult>(
            '/api/completions/bulk',
            payload,
            { headers: defaultHeaders() },
        );

        return data;
    }

    async function update(
        id: string,
        payload: CompletionUpdatePayload,
    ): Promise<CompletionRow> {
        const { data } = await axios.patch<CompletionRow>(
            `/api/completions/${id}`,
            payload,
            { headers: defaultHeaders() },
        );

        return data;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/completions/${id}`, {
            headers: defaultHeaders(),
        });
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`, 'private', {
            persist: true,
        });

        // The table is server-paged, so we can't patch a row in place — each
        // broadcast just nudges the open page to refetch itself.
        bind('CompletionCreated', () => revision.value++);
        bind('CompletionUpdated', () => revision.value++);
        bind('CompletionDeleted', () => revision.value++);
        // A bulk record carries no per-row payload (it can touch many users on
        // pages this tab hasn't loaded) — just nudge the open page to refetch.
        bind('CompletionsBulkChanged', () => revision.value++);
    }

    return {
        revision,
        fetchPage,
        create,
        bulkCreate,
        update,
        destroy,
        subscribe,
    };
});
