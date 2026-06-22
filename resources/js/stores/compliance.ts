/*
 * Compliance store — the data relay for the org-wide compliance roll-ups
 * (Compliance page). Each tab pulls a paginated {data, meta} page from its
 * dimension endpoint via useServerTable; the backend's ComplianceQuery does
 * the GROUP BY over the materialized status. Read-only: no mutations here.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import type {
    ServerTableQuery,
    ServerTableResponse,
} from '@/composables/useServerTable';
import { realtimeTabId } from '@/echo';

export interface ComplianceCounts {
    overdue: number;
    due_soon: number;
    not_started: number;
    current: number;
    as_needed: number;
}

export interface ComplianceRow {
    id: string;
    name: string;
    total: number;
    counts: ComplianceCounts;
}

/** Drill-down row: one user under a training/requirement, with their status. */
export interface ComplianceUserRow {
    user_id: string;
    name: string | null;
    status: string;
    expires_at: string | null;
    last_completed_at: string | null;
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

export const useComplianceStore = defineStore('compliance', () => {
    async function fetchPaged<T>(
        url: string,
        params: ServerTableQuery,
    ): Promise<ServerTableResponse<T>> {
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

        const { data } = await axios.get<ServerTableResponse<T>>(url, {
            headers: defaultHeaders(),
            params: query,
        });

        return data;
    }

    const byTraining = (params: ServerTableQuery) =>
        fetchPaged<ComplianceRow>('/api/compliance/by-training', params);

    const byRequirement = (params: ServerTableQuery) =>
        fetchPaged<ComplianceRow>('/api/compliance/by-requirement', params);

    const notRequired = (params: ServerTableQuery) =>
        fetchPaged<ComplianceRow>('/api/compliance/not-required', params);

    // Drill-down: users under one training / requirement.
    const trainingUsers = (id: string, params: ServerTableQuery) =>
        fetchPaged<ComplianceUserRow>(
            `/api/compliance/by-training/${id}/users`,
            params,
        );

    const requirementUsers = (id: string, params: ServerTableQuery) =>
        fetchPaged<ComplianceUserRow>(
            `/api/compliance/by-requirement/${id}/users`,
            params,
        );

    return {
        byTraining,
        byRequirement,
        notRequired,
        trainingUsers,
        requirementUsers,
    };
});
