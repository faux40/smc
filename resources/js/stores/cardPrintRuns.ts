/*
 * Card print runs (custom-certs C4d/C4e) — asking for a class topic's cards
 * and watching what became of the request.
 *
 * Cached per class, like the class detail itself: runs belong to a class and
 * two classes open in a session must not read each other's. The generated
 * sheets are NOT here — they are filed as class documents, which is where they
 * are viewed and downloaded. What lives here is the outcome, above all the
 * reason a run failed.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export type CardPrintRunStatus = 'queued' | 'processing' | 'done' | 'failed';

export interface CardPrintRunRow {
    id: string;
    class_training_id: string;
    topic_name: string | null;
    status: CardPrintRunStatus;
    /** Why it failed — the whole reason for listing runs at all. */
    error: string | null;
    card_count: number | null;
    sheet_count: number | null;
    include_backs: boolean;
    proof: boolean;
    start_cell: number;
    created_at: string | null;
}

export interface CardPrintRunPayload {
    class_training_id: string;
    /** Print-time override; omit or null to use the training's own design. */
    card_template_id?: string | null;
    card_stock_id: string;
    start_cell: number;
    include_backs: boolean;
    /** Print only the first card (C6b) — a positioning check, not a run. */
    proof: boolean;
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

function url(classId: string): string {
    return `/api/classes/${classId}/card-runs`;
}

export const useCardPrintRunsStore = defineStore('cardPrintRuns', () => {
    const byClass = ref<Record<string, CardPrintRunRow[]>>({});
    const loaded = ref<Record<string, boolean>>({});
    const subscribedOrgId = ref<string | null>(null);

    /** This class's runs, newest first; [] when none are known yet. */
    function runsFor(classId: string): CardPrintRunRow[] {
        return byClass.value[classId] ?? [];
    }

    async function load(classId: string): Promise<void> {
        if (loaded.value[classId]) {
            return;
        }

        await reload(classId);
    }

    async function reload(classId: string): Promise<void> {
        const { data } = await axios.get<CardPrintRunRow[]>(url(classId), {
            headers: defaultHeaders(),
        });

        byClass.value = { ...byClass.value, [classId]: data };
        loaded.value = { ...loaded.value, [classId]: true };
    }

    async function create(
        classId: string,
        payload: CardPrintRunPayload,
    ): Promise<CardPrintRunRow> {
        const { data } = await axios.post<CardPrintRunRow>(
            url(classId),
            payload,
            { headers: defaultHeaders() },
        );

        byClass.value = {
            ...byClass.value,
            [classId]: [data, ...runsFor(classId)],
        };

        return data;
    }

    /**
     * Clear a run from the list. The sheets it filed are class documents with
     * their own delete and are left alone — this only dismisses the record.
     *
     * Removed after the server agrees: dropping it optimistically would show
     * the run gone until the next fetch put it back.
     */
    async function destroy(classId: string, runId: string): Promise<void> {
        await axios.delete(`${url(classId)}/${runId}`, {
            headers: defaultHeaders(),
        });

        byClass.value = {
            ...byClass.value,
            [classId]: runsFor(classId).filter((r) => r.id !== runId),
        };
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`, 'private', {
            persist: true,
        });

        /*
         * The job broadcasts ClassChanged when it finishes — there is no
         * finer-grained event, and this is how a queued run becomes done or
         * failed on screen. Only classes already on display are refetched;
         * a bare org event must not start fetching runs for classes nobody
         * is looking at.
         */
        bind('ClassChanged', (p: { class_id: string; action: string }) => {
            if (p.action === 'deleted') {
                const next = { ...byClass.value };
                delete next[p.class_id];
                byClass.value = next;

                const stillLoaded = { ...loaded.value };
                delete stillLoaded[p.class_id];
                loaded.value = stillLoaded;

                return;
            }

            if (loaded.value[p.class_id]) {
                void reload(p.class_id);
            }
        });
    }

    return {
        byClass,
        loaded,
        subscribedOrgId,
        runsFor,
        load,
        reload,
        create,
        destroy,
        subscribe,
    };
});
