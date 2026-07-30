/*
 * Custom card fields (custom-certs C3) — a training's own `${keys}`.
 *
 * Cached per training rather than in one list: definitions belong to a
 * training, and two trainings open in a session must not read each other's.
 * The sync response replaces a training's entry wholesale, because the server
 * owns seq and the rendered placeholder.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { realtimeTabId } from '@/echo';
import type { CardFieldPayload, CardFieldRow } from '@/lib/cardFields';

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

function url(trainingId: string): string {
    return `/api/trainings/${trainingId}/card-fields`;
}

export const useCardFieldsStore = defineStore('cardFields', () => {
    const byTraining = ref<Record<string, CardFieldRow[]>>({});
    const loaded = ref<Record<string, boolean>>({});

    /** Cached definitions for a training; [] when it hasn't been fetched. */
    function forTraining(trainingId: string): CardFieldRow[] {
        return byTraining.value[trainingId] ?? [];
    }

    function isLoaded(trainingId: string): boolean {
        return loaded.value[trainingId] === true;
    }

    async function load(trainingId: string): Promise<void> {
        if (isLoaded(trainingId)) {
            return;
        }

        await reload(trainingId);
    }

    async function reload(trainingId: string): Promise<void> {
        const { data } = await axios.get<CardFieldRow[]>(url(trainingId), {
            headers: defaultHeaders(),
        });

        byTraining.value = { ...byTraining.value, [trainingId]: data };
        loaded.value = { ...loaded.value, [trainingId]: true };
    }

    /**
     * Replace the whole set. Rows absent from `fields` are deleted server-side
     * (with the answers entered against them), and order becomes seq.
     */
    async function sync(
        trainingId: string,
        fields: CardFieldPayload[],
    ): Promise<CardFieldRow[]> {
        const { data } = await axios.put<CardFieldRow[]>(
            url(trainingId),
            { fields },
            { headers: defaultHeaders() },
        );

        byTraining.value = { ...byTraining.value, [trainingId]: data };
        loaded.value = { ...loaded.value, [trainingId]: true };

        return data;
    }

    return { byTraining, loaded, forTraining, isLoaded, load, reload, sync };
});
