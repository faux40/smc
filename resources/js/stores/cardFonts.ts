/*
 * The org's uploaded font library (custom-certs C6c).
 *
 * LibreOffice only embeds fonts it can SEE: a family that isn't installed is
 * substituted at conversion and the card re-flows at different metrics. A row
 * here is a file the print run stages so the converter finds it — which is
 * what makes a design's "not installed" warning go away.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface CardFontRow {
    id: string;
    /** As the FILE declares it, never the filename it was uploaded under. */
    family: string;
    original_filename: string;
    format: string;
    size: number;
    uploaded_at: string | null;
    can_delete: boolean;
}

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}

export const useCardFontsStore = defineStore('cardFonts', () => {
    const library = ref<CardFontRow[]>([]);
    const loaded = ref(false);

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        await reload();
    }

    async function reload(): Promise<void> {
        const { data } = await axios.get<CardFontRow[]>('/api/card-fonts', {
            headers: defaultHeaders(),
        });

        library.value = data;
        loaded.value = true;
    }

    async function upload(file: File): Promise<CardFontRow> {
        const form = new FormData();
        form.append('file', file);

        const { data } = await axios.post<CardFontRow>(
            '/api/card-fonts',
            form,
            { headers: defaultHeaders() },
        );

        library.value = [...library.value, data].sort((a, b) =>
            a.family.localeCompare(b.family),
        );

        return data;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/card-fonts/${id}`, {
            headers: defaultHeaders(),
        });

        library.value = library.value.filter((f) => f.id !== id);
    }

    return { library, loaded, load, reload, upload, destroy };
});
