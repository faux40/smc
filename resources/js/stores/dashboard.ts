/*
 * Dashboard store — the data relay for the manager dashboard widgets. Owns the
 * server-paged "needs action" feed so components never reach for axios
 * directly (project rule). The backend does the status filter / search /
 * paging in SQL over the materialized training_assignments.status; this just
 * marshals params into the shared {data, meta} contract useServerTable speaks.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { realtimeTabId } from '@/echo';
import type { ComplianceStatus } from '@/pages/users/Partials/ComplianceStatusBadge.vue';

export interface SourceChip {
    type: 'direct' | 'requirement';
    id: string | null;
    name: string | null;
}

export interface NeedsActionRow {
    id: string;
    user_id: string;
    user_name: string | null;
    training_id: string;
    training_name: string;
    status: ComplianceStatus;
    expires_at: string | null;
    days_until_due: number | null;
    sources: SourceChip[];
    /** Covering training satisfying this row, when the hierarchy applies. */
    satisfied_via_training_name?: string | null;
}

/** Server-table params plus the optional status-chip filter. */
export type NeedsActionQuery = ServerTableQuery & { status?: string };

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

export const useDashboardStore = defineStore('dashboard', () => {
    async function needsAction(
        params: NeedsActionQuery,
    ): Promise<ServerTableResponse<NeedsActionRow>> {
        // Ordering is fixed server-side (worst-first), so sort/dir aren't sent.
        const query: Record<string, string | number> = {
            page: params.page,
            per_page: params.per_page,
        };

        if (params.q) {
            query.q = params.q;
        }

        if (params.status) {
            query.status = params.status;
        }

        const { data } = await axios.get<ServerTableResponse<NeedsActionRow>>(
            '/api/dashboard/needs-action',
            { headers: defaultHeaders(), params: query },
        );

        return data;
    }

    return { needsAction };
});
