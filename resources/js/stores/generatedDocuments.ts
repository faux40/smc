/*
 * Generated-documents store (Phase D2) — the org's output archive.
 * Server-paginated ({data, meta} for useServerTable); `revision` bumps
 * on the coarse broadcast so open tables refetch when a queued
 * generation finishes (including this tab's own runs — the job's
 * broadcast has no origin tab, which is exactly what we want here).
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export type GeneratedDocumentStatus =
    | 'queued'
    | 'processing'
    | 'done'
    | 'failed';

export interface GeneratedDocumentRow {
    id: string;
    template_id: string | null;
    template_name: string | null;
    extension: string | null;
    location: string;
    department: string;
    status: GeneratedDocumentStatus;
    error: string | null;
    filename: string;
    requested_by_name: string | null;
    created_at: string | null;
    /** Last status change — for a failed row, when it failed. */
    updated_at: string | null;
}

export interface GeneratedDocumentsPage {
    data: GeneratedDocumentRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
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

export const useGeneratedDocumentsStore = defineStore(
    'generatedDocuments',
    () => {
        const revision = ref(0);
        const subscribedOrgId = ref<string | null>(null);

        async function fetchPage(
            query: Record<string, unknown>,
        ): Promise<GeneratedDocumentsPage> {
            const { data } = await axios.get<GeneratedDocumentsPage>(
                '/api/generated-documents',
                { headers: defaultHeaders(), params: query },
            );

            return data;
        }

        async function generate(
            templateId: string,
            location: string,
            department: string,
        ): Promise<GeneratedDocumentRow> {
            const { data } = await axios.post<GeneratedDocumentRow>(
                '/api/generated-documents',
                { doc_template_id: templateId, location, department },
                { headers: defaultHeaders() },
            );
            revision.value++;

            return data;
        }

        /**
         * Re-queue a failed row in place, keeping its template + variation.
         * The server 409s if the row did not fail or its template is gone.
         */
        async function retry(id: string): Promise<GeneratedDocumentRow> {
            const { data } = await axios.post<GeneratedDocumentRow>(
                `/api/generated-documents/${id}/retry`,
                {},
                { headers: defaultHeaders() },
            );
            revision.value++;

            return data;
        }

        async function destroy(id: string): Promise<void> {
            await axios.delete(`/api/generated-documents/${id}`, {
                headers: defaultHeaders(),
            });
            revision.value++;
        }

        function downloadUrl(id: string, format: 'pdf' | 'merged'): string {
            return `/api/generated-documents/${id}/download?format=${format}`;
        }

        function subscribe(orgId: string): void {
            if (subscribedOrgId.value === orgId) {
                return;
            }

            subscribedOrgId.value = orgId;

            const { bind } = useRealtime(`org.${orgId}`, 'private', {
                persist: true,
            });
            bind(
                'GeneratedDocumentsChanged',
                (p: { origin_tab?: string | null }) => {
                    // Deliberately NOT filtering self-echo: the completion signal
                    // for this tab's own generation arrives via this event (the
                    // queue worker has no origin tab).
                    void p;
                    revision.value++;
                },
            );
        }

        return {
            revision,
            fetchPage,
            generate,
            retry,
            destroy,
            downloadUrl,
            subscribe,
        };
    },
);
