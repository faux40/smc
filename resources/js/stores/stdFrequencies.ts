/*
 * Std frequencies store — per-org library of timing presets.
 *
 * Used by the Settings/Frequencies admin page AND by downstream pickers
 * (Trainings / RqmtElements / Assignments forms in Phases 8+). Library
 * cache + Reverb subscription mirror useTagsStore's library half.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { realtimeTabId } from '@/echo';
import { useRealtime } from '@/composables/useRealtime';

export interface StdFrequencyRow {
    id: string;
    name: string;
    repeat_days: number;
    can_edit: boolean;
    can_delete: boolean;
}

function defaultHeaders(): Record<string, string> {
    const csrf = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}

export const useStdFrequenciesStore = defineStore('stdFrequencies', () => {
    const library = ref<StdFrequencyRow[]>([]);
    const loaded = ref(false);
    const subscribedOrgId = ref<string | null>(null);

    async function load(): Promise<void> {
        if (loaded.value) return;
        const { data } = await axios.get<StdFrequencyRow[]>('/api/std-frequencies', {
            headers: defaultHeaders(),
        });
        library.value = data;
        loaded.value = true;
    }

    async function create(name: string, repeatDays: number): Promise<StdFrequencyRow> {
        const { data } = await axios.post<StdFrequencyRow>(
            '/api/std-frequencies',
            { name, repeat_days: repeatDays },
            { headers: defaultHeaders() },
        );
        library.value = [...library.value, { ...data, can_edit: true, can_delete: true }];
        return data;
    }

    async function update(id: string, name: string, repeatDays: number): Promise<void> {
        const { data } = await axios.patch<StdFrequencyRow>(
            `/api/std-frequencies/${id}`,
            { name, repeat_days: repeatDays },
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((f) =>
            f.id === id ? { ...f, name: data.name, repeat_days: data.repeat_days } : f,
        );
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/std-frequencies/${id}`, { headers: defaultHeaders() });
        library.value = library.value.filter((f) => f.id !== id);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) return;
        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind('StdFrequencyCreated', (p: { id: string; name: string; repeat_days: number }) => {
            if (!library.value.some((f) => f.id === p.id)) {
                library.value = [
                    ...library.value,
                    {
                        id: p.id,
                        name: p.name,
                        repeat_days: p.repeat_days,
                        can_edit: false,
                        can_delete: false,
                    },
                ];
            }
        });
        bind('StdFrequencyUpdated', (p: { id: string; name: string; repeat_days: number }) => {
            library.value = library.value.map((f) =>
                f.id === p.id ? { ...f, name: p.name, repeat_days: p.repeat_days } : f,
            );
        });
        bind('StdFrequencyDeleted', (p: { id: string }) => {
            library.value = library.value.filter((f) => f.id !== p.id);
        });
    }

    return { library, loaded, load, create, update, destroy, subscribe };
});
