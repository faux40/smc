/*
 * rqmt_elements store — per-requirement element lists.
 *
 * Mirrors useCommentsStore's shape (per-parent cache). The detail page
 * for a single Requirement calls loadFor(requirementId) once and then
 * reads reactively; peer broadcasts patch the list in place.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface RqmtElementRow {
    id: string;
    requirement_id: string;
    module_type: string;
    module_id: string;
    /** Effective display name: the override when set, else the module's live name. */
    name: string;
    /** The raw override — null means the element follows the training's name. */
    custom_name: string | null;
    /** The module's live name, for showing a diverged override beside the real one. */
    module_name: string | null;
    description: string | null;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
    can_edit: boolean;
    can_delete: boolean;
}

export interface RqmtElementCreatePayload {
    module_type: string;
    module_id: string;
    /** Override label only — null follows the training's live name. */
    name: string | null;
    description: string | null;
    initial_only: boolean;
    repeating: boolean;
    std_freq_id: string | null;
    as_needed: boolean;
}

export type RqmtElementUpdatePayload = Omit<
    RqmtElementCreatePayload,
    'module_type' | 'module_id'
>;

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

export const useRqmtElementsStore = defineStore('rqmtElements', () => {
    const lists = ref<Record<string, RqmtElementRow[]>>({});
    const loaded = ref<Record<string, boolean>>({});
    const subscribedOrgId = ref<string | null>(null);

    function listFor(requirementId: string): RqmtElementRow[] {
        return lists.value[requirementId] ?? [];
    }

    async function loadFor(requirementId: string): Promise<void> {
        if (loaded.value[requirementId]) {
            return;
        }

        const { data } = await axios.get<RqmtElementRow[]>(
            `/api/requirements/${requirementId}/elements`,
            { headers: defaultHeaders() },
        );
        lists.value = { ...lists.value, [requirementId]: data };
        loaded.value = { ...loaded.value, [requirementId]: true };
    }

    async function create(
        requirementId: string,
        payload: RqmtElementCreatePayload,
    ): Promise<void> {
        await axios.post(
            `/api/requirements/${requirementId}/elements`,
            payload,
            { headers: defaultHeaders() },
        );
        loaded.value = { ...loaded.value, [requirementId]: false };
        await loadFor(requirementId);
    }

    async function update(
        elementId: string,
        requirementId: string,
        payload: RqmtElementUpdatePayload,
    ): Promise<void> {
        await axios.patch(`/api/rqmt-elements/${elementId}`, payload, {
            headers: defaultHeaders(),
        });
        loaded.value = { ...loaded.value, [requirementId]: false };
        await loadFor(requirementId);
    }

    async function destroy(
        elementId: string,
        requirementId: string,
    ): Promise<void> {
        await axios.delete(`/api/rqmt-elements/${elementId}`, {
            headers: defaultHeaders(),
        });
        const cur = lists.value[requirementId] ?? [];
        lists.value = {
            ...lists.value,
            [requirementId]: cur.filter((e) => e.id !== elementId),
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

        bind(
            'RqmtElementCreated',
            (p: RqmtElementRow & { origin_tab?: string }) => {
                const cur = lists.value[p.requirement_id];

                if (!cur) {
                    return;
                }

                if (cur.some((e) => e.id === p.id)) {
                    return;
                }

                lists.value = {
                    ...lists.value,
                    [p.requirement_id]: [
                        ...cur,
                        { ...p, can_edit: false, can_delete: false },
                    ],
                };
            },
        );
        bind(
            'RqmtElementUpdated',
            (p: RqmtElementRow & { origin_tab?: string }) => {
                const cur = lists.value[p.requirement_id];

                if (!cur) {
                    return;
                }

                lists.value = {
                    ...lists.value,
                    [p.requirement_id]: cur.map((e) =>
                        e.id === p.id ? { ...e, ...p } : e,
                    ),
                };
            },
        );
        bind(
            'RqmtElementDeleted',
            (p: { id: string; requirement_id: string }) => {
                const cur = lists.value[p.requirement_id];

                if (!cur) {
                    return;
                }

                lists.value = {
                    ...lists.value,
                    [p.requirement_id]: cur.filter((e) => e.id !== p.id),
                };
            },
        );
    }

    return {
        lists,
        loaded,
        listFor,
        loadFor,
        create,
        update,
        destroy,
        subscribe,
    };
});
