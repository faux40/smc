/*
 * Completions store — records that a user satisfied one or more
 * rqmt_elements (and therefore one or more Requirements) on a date.
 *
 * Backend API is flat (`/api/completions`) with an optional ?user_id
 * filter; the store mirrors that. Rows live in a flat array keyed by id
 * with `forUser` / `forElement` computed selectors. The pivot links to
 * rqmt_elements are carried in `rqmt_element_ids` and updated via
 * sync() server-side on every create/update.
 *
 * Phase 10 ships the store with no UI; Phases 11/12 consume it.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface CompletionRow {
    id: string;
    user_id: string;
    module_type: string;
    module_id: string;
    completion_date: string | null;
    certification_date: string | null;
    expire_date: string | null;
    cert_ident: string | null;
    notes: string | null;
    rqmt_element_ids: string[];
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
    notes: string | null;
    rqmt_element_ids: string[];
}

export type CompletionUpdatePayload = Omit<
    CompletionCreatePayload,
    'user_id' | 'module_type' | 'module_id'
>;

export interface CompletionFilter {
    user_id?: string;
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

function filterKey(filter: CompletionFilter): string {
    return `u:${filter.user_id ?? ''}`;
}

export const useCompletionsStore = defineStore('completions', () => {
    const rows = ref<CompletionRow[]>([]);
    const loadedFilters = ref<Set<string>>(new Set());
    const subscribedOrgId = ref<string | null>(null);

    const forUser = computed(
        () => (userId: string) =>
            rows.value.filter((c) => c.user_id === userId),
    );
    const forElement = computed(
        () => (elementId: string) =>
            rows.value.filter((c) => c.rqmt_element_ids.includes(elementId)),
    );

    function upsert(row: CompletionRow): void {
        const idx = rows.value.findIndex((c) => c.id === row.id);

        if (idx === -1) {
            rows.value = [...rows.value, row];
        } else {
            const next = rows.value.slice();
            next[idx] = { ...next[idx], ...row };
            rows.value = next;
        }
    }

    async function loadFor(filter: CompletionFilter = {}): Promise<void> {
        const key = filterKey(filter);

        if (loadedFilters.value.has(key)) {
            return;
        }

        const params: Record<string, string> = {};

        if (filter.user_id) {
            params.user_id = filter.user_id;
        }

        const { data } = await axios.get<CompletionRow[]>('/api/completions', {
            headers: defaultHeaders(),
            params,
        });
        data.forEach((r) => upsert(r));
        loadedFilters.value = new Set([...loadedFilters.value, key]);
    }

    async function create(
        payload: CompletionCreatePayload,
    ): Promise<CompletionRow> {
        const { data } = await axios.post<CompletionRow>(
            '/api/completions',
            payload,
            { headers: defaultHeaders() },
        );
        upsert(data);

        return data;
    }

    async function update(
        id: string,
        payload: CompletionUpdatePayload,
    ): Promise<void> {
        const { data } = await axios.patch<CompletionRow>(
            `/api/completions/${id}`,
            payload,
            { headers: defaultHeaders() },
        );
        upsert(data);
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/completions/${id}`, {
            headers: defaultHeaders(),
        });
        rows.value = rows.value.filter((c) => c.id !== id);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind('CompletionCreated', (p: CompletionRow) => {
            upsert({ ...p, can_edit: false, can_delete: false });
        });
        bind('CompletionUpdated', (p: CompletionRow) => {
            const existing = rows.value.find((c) => c.id === p.id);

            if (!existing) {
                return;
            }

            upsert({ ...existing, ...p });
        });
        bind('CompletionDeleted', (p: { id: string }) => {
            rows.value = rows.value.filter((c) => c.id !== p.id);
        });
    }

    return {
        rows,
        loadedFilters,
        forUser,
        forElement,
        loadFor,
        create,
        update,
        destroy,
        subscribe,
    };
});
