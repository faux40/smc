/*
 * Trainings store — first concrete module library.
 *
 * Used by the Trainings admin page AND by downstream rqmt_elements pickers
 * (Phases 9+ — when an admin adds a Training-typed element to a Requirement,
 * the picker reads from here). Mirrors useStdFrequenciesStore's library
 * shape.
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

export interface TrainingRow {
    id: string;
    name: string;
    nickname: string | null;
    description: string | null;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    std_freq_name: string | null;
    /** Day count behind std_freq_name — F9's completion-form auto-fill math. */
    std_freq_repeat_days: number | null;
    as_needed: boolean;
    default_hours: string | null;
    cert_title: string | null;
    cert_text: string | null;
    cert_code: string | null;
    /** Custom card design printed for this training; null = none. */
    card_template_id: string | null;
    card_stock_id: string | null;
    default_trainer: string | null;
    default_location: string | null;
    default_address: string | null;
    can_edit: boolean;
    can_delete: boolean;
}

export interface TrainingFormPayload {
    name: string;
    nickname: string | null;
    description: string | null;
    default_hours: number | null;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
    cert_title: string | null;
    cert_text: string | null;
    cert_code: string | null;
    card_template_id: string | null;
    card_stock_id: string | null;
    default_trainer: string | null;
    default_location: string | null;
    default_address: string | null;
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

export const useTrainingsStore = defineStore('trainings', () => {
    const library = ref<TrainingRow[]>([]);
    const loaded = ref(false);
    const subscribedOrgId = ref<string | null>(null);

    // Bumped on any mutation/broadcast so the paged Index re-pulls its page.
    const revision = ref(0);

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        const { data } = await axios.get<TrainingRow[]>('/api/trainings', {
            headers: defaultHeaders(),
        });
        library.value = data;
        loaded.value = true;
    }

    /**
     * Server-paged fetch for the trainings table ({data, meta}). The Index
     * drives it via useServerTable; does not touch the picker `library` cache.
     */
    async function fetchPage(
        params: ServerTableQuery,
    ): Promise<ServerTableResponse<TrainingRow>> {
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

        const { data } = await axios.get<ServerTableResponse<TrainingRow>>(
            '/api/trainings/list',
            { headers: defaultHeaders(), params: query },
        );

        return data;
    }

    async function create(payload: TrainingFormPayload): Promise<TrainingRow> {
        const { data } = await axios.post<TrainingRow>(
            '/api/trainings',
            payload,
            { headers: defaultHeaders() },
        );
        library.value = [
            ...library.value,
            {
                ...data,
                std_freq_name: null,
                std_freq_repeat_days: null,
                can_edit: true,
                can_delete: true,
            } as TrainingRow,
        ];
        // Re-fetch to pick up std_freq_name + accurate can_* from the server.
        loaded.value = false;
        await load();
        revision.value++;

        return data;
    }

    async function update(
        id: string,
        payload: TrainingFormPayload,
    ): Promise<void> {
        await axios.patch(`/api/trainings/${id}`, payload, {
            headers: defaultHeaders(),
        });
        loaded.value = false;
        await load();
        revision.value++;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/trainings/${id}`, {
            headers: defaultHeaders(),
        });
        library.value = library.value.filter((t) => t.id !== id);
        revision.value++;
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`, 'private', {
            persist: true,
        });

        bind('TrainingCreated', (p: TrainingRow & { origin_tab?: string }) => {
            if (!library.value.some((t) => t.id === p.id)) {
                library.value = [
                    ...library.value,
                    {
                        ...p,
                        std_freq_name: null,
                        std_freq_repeat_days: null,
                        can_edit: false,
                        can_delete: false,
                    },
                ];
            }

            revision.value++;
        });
        bind('TrainingUpdated', (p: TrainingRow & { origin_tab?: string }) => {
            library.value = library.value.map((t) =>
                t.id === p.id ? { ...t, ...p } : t,
            );
            revision.value++;
        });
        bind('TrainingDeleted', (p: { id: string }) => {
            library.value = library.value.filter((t) => t.id !== p.id);
            revision.value++;
        });
    }

    return {
        library,
        loaded,
        revision,
        load,
        fetchPage,
        create,
        update,
        destroy,
        subscribe,
    };
});
