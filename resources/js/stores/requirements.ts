/*
 * Requirements store — org's compliance library.
 *
 * Used by /requirements and downstream assignment pickers. Mirrors
 * the trainings store shape.
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

export interface RequirementRow {
    id: string;
    name: string;
    description: string | null;
    elements_count: number;
    can_edit: boolean;
    can_delete: boolean;
}

export interface RequirementFormPayload {
    name: string;
    description: string | null;
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

export const useRequirementsStore = defineStore('requirements', () => {
    const library = ref<RequirementRow[]>([]);
    const loaded = ref(false);
    const subscribedOrgId = ref<string | null>(null);
    // Bumped on every requirement broadcast — the paged Index watches it and
    // refetches its current page.
    const revision = ref(0);

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        const { data } = await axios.get<RequirementRow[]>(
            '/api/requirements',
            {
                headers: defaultHeaders(),
            },
        );
        library.value = data;
        loaded.value = true;
    }

    /**
     * Server-paged fetch for the requirements admin table ({data, meta}
     * contract). Does not touch the cached library — the Index drives it via
     * useServerTable and renders the returned page.
     */
    async function fetchPage(
        params: ServerTableQuery,
    ): Promise<ServerTableResponse<RequirementRow>> {
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

        const { data } = await axios.get<ServerTableResponse<RequirementRow>>(
            '/api/requirements/paged',
            { headers: defaultHeaders(), params: query },
        );

        return data;
    }

    async function create(
        payload: RequirementFormPayload,
    ): Promise<RequirementRow> {
        const { data } = await axios.post<RequirementRow>(
            '/api/requirements',
            payload,
            { headers: defaultHeaders() },
        );
        loaded.value = false;
        await load();

        return data;
    }

    async function update(
        id: string,
        payload: RequirementFormPayload,
    ): Promise<void> {
        await axios.patch(`/api/requirements/${id}`, payload, {
            headers: defaultHeaders(),
        });
        loaded.value = false;
        await load();
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/requirements/${id}`, {
            headers: defaultHeaders(),
        });
        library.value = library.value.filter((r) => r.id !== id);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind(
            'RequirementCreated',
            (p: { id: string; name: string; description: string | null }) => {
                revision.value++;

                if (!library.value.some((r) => r.id === p.id)) {
                    library.value = [
                        ...library.value,
                        {
                            id: p.id,
                            name: p.name,
                            description: p.description,
                            elements_count: 0,
                            can_edit: false,
                            can_delete: false,
                        },
                    ];
                }
            },
        );
        bind(
            'RequirementUpdated',
            (p: { id: string; name: string; description: string | null }) => {
                revision.value++;
                library.value = library.value.map((r) =>
                    r.id === p.id
                        ? { ...r, name: p.name, description: p.description }
                        : r,
                );
            },
        );
        bind('RequirementDeleted', (p: { id: string }) => {
            revision.value++;
            library.value = library.value.filter((r) => r.id !== p.id);
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
