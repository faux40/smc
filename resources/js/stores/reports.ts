/*
 * Reports store — data relay for the org completion report (Reports page).
 * The on-screen table pulls a paginated {data, meta} page; PDF export is a
 * direct GET to the export endpoint (built as a URL in the page). Read-only.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { realtimeTabId } from '@/echo';

export interface CompletionReportRow {
    id: string;
    user_id: string;
    // Tag IDs attached to this row's user; hydrates the tags store so the
    // Tags column renders pills without a per-row fetch.
    tag_ids: string[];
    user: string;
    employee_number: string;
    department: string;
    location: string;
    training: string;
    completion_date: string;
    expire_date: string;
    status: string;
    // Expiry colour-band key: 'expired' | 'due_soon' | 'current'.
    _band: string;
    hours: string | number;
    class: string;
    cert_id: string;
}

/** Completion-report query: server-table params + the report filters. */
export type CompletionReportQuery = ServerTableQuery & {
    from?: string;
    to?: string;
    user_q?: string;
    tags?: string[];
    tags_mode?: string;
    statuses?: string[];
};

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

export const useReportsStore = defineStore('reports', () => {
    async function fetchCompletions(
        params: CompletionReportQuery,
    ): Promise<ServerTableResponse<CompletionReportRow>> {
        const query: Record<string, string | number | string[]> = {
            page: params.page,
            per_page: params.per_page,
        };

        if (params.q) {
            query.q = params.q;
        }
        if (params.from) {
            query.from = params.from;
        }
        if (params.to) {
            query.to = params.to;
        }
        if (params.user_q) {
            query.user_q = params.user_q;
        }
        if (params.tags && params.tags.length > 0) {
            query.tags = params.tags;
            query.tags_mode = params.tags_mode ?? 'and';
        }
        if (params.statuses && params.statuses.length > 0) {
            query.statuses = params.statuses;
        }

        const { data } = await axios.get<
            ServerTableResponse<CompletionReportRow>
        >('/api/reports/completions', { headers: defaultHeaders(), params: query });

        return data;
    }

    return { fetchCompletions };
});
