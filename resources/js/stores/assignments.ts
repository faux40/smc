/*
 * Assignments store — per-(user, requirement) compliance timing records.
 *
 * Backend API is flat (`/api/assignments`) with optional `?user_id=` /
 * `?requirement_id=` query filters; this store mirrors that. Rows live in
 * a flat array keyed by id; the consumer picks the slice via `forUser` /
 * `forRequirement` selectors. Broadcasts patch by id, so a single record
 * stays consistent across any view that's loaded it.
 *
 * Phase 10 ships the store with no UI; Phases 11/12 consume it.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

/**
 * Per-timing-type element counts for the assignment's requirement. Timing
 * lives on the requirement's elements (a requirement can mix repeating /
 * initial-only / as-needed), so the assignment carries the breakdown rather
 * than a single flag. Drives the dots on AssignmentPill.
 */
export interface ElementTimingSummary {
    initial: number;
    repeating: number;
    as_needed: number;
    none: number;
}

export interface AssignmentRow {
    id: string;
    user_id: string;
    requirement_id: string;
    name: string;
    description: string | null;
    element_timing: ElementTimingSummary;
    start_date: string | null;
    end_date: string | null;
    can_edit: boolean;
    can_delete: boolean;
}

export interface AssignmentCreatePayload {
    user_id: string;
    requirement_id: string;
    description: string | null;
    start_date: string;
    end_date: string | null;
}

export type AssignmentUpdatePayload = Omit<
    AssignmentCreatePayload,
    'user_id' | 'requirement_id'
>;

export interface AssignmentFilter {
    user_id?: string;
    requirement_id?: string;
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

function filterKey(filter: AssignmentFilter): string {
    return `u:${filter.user_id ?? ''}|r:${filter.requirement_id ?? ''}`;
}

export const useAssignmentsStore = defineStore('assignments', () => {
    const rows = ref<AssignmentRow[]>([]);
    // Filter signatures already fetched, so repeated `loadFor` calls are no-ops.
    const loadedFilters = ref<Set<string>>(new Set());
    const subscribedOrgId = ref<string | null>(null);

    const forUser = computed(
        () => (userId: string) =>
            rows.value.filter((a) => a.user_id === userId),
    );
    const forRequirement = computed(
        () => (requirementId: string) =>
            rows.value.filter((a) => a.requirement_id === requirementId),
    );

    function upsert(row: AssignmentRow): void {
        const idx = rows.value.findIndex((a) => a.id === row.id);

        if (idx === -1) {
            rows.value = [...rows.value, row];
        } else {
            const next = rows.value.slice();
            next[idx] = { ...next[idx], ...row };
            rows.value = next;
        }
    }

    async function loadFor(
        filter: AssignmentFilter = {},
        includeExpired = false,
    ): Promise<void> {
        const key = `${filterKey(filter)}|e:${includeExpired ? 1 : 0}`;

        if (loadedFilters.value.has(key)) {
            return;
        }

        const params: Record<string, string> = {};

        if (filter.user_id) {
            params.user_id = filter.user_id;
        }

        if (filter.requirement_id) {
            params.requirement_id = filter.requirement_id;
        }

        if (includeExpired) {
            params.include_expired = '1';
        }

        const { data } = await axios.get<AssignmentRow[]>('/api/assignments', {
            headers: defaultHeaders(),
            params,
        });
        data.forEach((r) => upsert(r));
        loadedFilters.value = new Set([...loadedFilters.value, key]);
    }

    async function create(
        payload: AssignmentCreatePayload,
    ): Promise<AssignmentRow> {
        const { data } = await axios.post<AssignmentRow>(
            '/api/assignments',
            payload,
            { headers: defaultHeaders() },
        );
        upsert(data);

        return data;
    }

    async function update(
        id: string,
        payload: AssignmentUpdatePayload,
    ): Promise<void> {
        const { data } = await axios.patch<AssignmentRow>(
            `/api/assignments/${id}`,
            payload,
            { headers: defaultHeaders() },
        );
        upsert(data);
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/assignments/${id}`, {
            headers: defaultHeaders(),
        });
        rows.value = rows.value.filter((a) => a.id !== id);
    }

    // Force a full re-fetch of the org's assignments, replacing rows. Used
    // after a bulk operation (the acting tab doesn't get its own broadcasts)
    // and when the "show expired" toggle flips.
    async function reload(includeExpired = false): Promise<void> {
        const { data } = await axios.get<AssignmentRow[]>('/api/assignments', {
            headers: defaultHeaders(),
            params: includeExpired ? { include_expired: '1' } : {},
        });
        rows.value = data;
        loadedFilters.value = new Set([
            `${filterKey({})}|e:${includeExpired ? 1 : 0}`,
        ]);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind('AssignmentCreated', (p: AssignmentRow) => {
            // Peer broadcast — actor lost edit/delete rights by default;
            // a follow-on hydrate would refresh `can_edit` / `can_delete`.
            upsert({ ...p, can_edit: false, can_delete: false });
        });
        bind('AssignmentUpdated', (p: AssignmentRow) => {
            const existing = rows.value.find((a) => a.id === p.id);

            // Patch in place (incl. a changed end_date — the page decides
            // whether an expired row is shown). The broadcast doesn't carry
            // can_edit/can_delete, so keep the existing row's (peer → false).
            upsert({
                ...p,
                can_edit: existing?.can_edit ?? false,
                can_delete: existing?.can_delete ?? false,
            });
        });
        bind('AssignmentDeleted', (p: { id: string }) => {
            rows.value = rows.value.filter((a) => a.id !== p.id);
        });
    }

    return {
        rows,
        loadedFilters,
        forUser,
        forRequirement,
        loadFor,
        create,
        update,
        destroy,
        reload,
        subscribe,
    };
});
