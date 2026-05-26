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

    // A past end_date means the window has closed — same rule the API index
    // uses to hide it. end_date null = active forever; end_date today = still
    // its last active day.
    function isExpired(row: AssignmentRow): boolean {
        return (
            row.end_date !== null &&
            row.end_date < new Date().toISOString().slice(0, 10)
        );
    }

    // Apply a create/update from any source (mutation or broadcast): patch the
    // row in place, OR drop it if the change made it expired — so an edited /
    // bulk-ended assignment falls off the list live, matching the index.
    function upsertOrDrop(row: AssignmentRow): void {
        if (isExpired(row)) {
            rows.value = rows.value.filter((a) => a.id !== row.id);

            return;
        }

        upsert(row);
    }

    async function loadFor(filter: AssignmentFilter = {}): Promise<void> {
        const key = filterKey(filter);

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
        upsertOrDrop(data);

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
        upsertOrDrop(data);
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/assignments/${id}`, {
            headers: defaultHeaders(),
        });
        rows.value = rows.value.filter((a) => a.id !== id);
    }

    // Force a full re-fetch of the org's assignments, replacing rows. Used
    // after a bulk operation: the acting tab doesn't receive its own
    // broadcasts (self-echo is suppressed), so it must refresh explicitly.
    async function reload(): Promise<void> {
        const { data } = await axios.get<AssignmentRow[]>('/api/assignments', {
            headers: defaultHeaders(),
        });
        rows.value = data;
        loadedFilters.value = new Set([filterKey({})]);
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
            upsertOrDrop({ ...p, can_edit: false, can_delete: false });
        });
        bind('AssignmentUpdated', (p: AssignmentRow) => {
            const existing = rows.value.find((a) => a.id === p.id);

            // Drop if the edit expired it; add if it un-expired (end_date
            // pushed into the future). The broadcast doesn't carry
            // can_edit/can_delete, so keep the existing row's (peer → false).
            upsertOrDrop({
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
