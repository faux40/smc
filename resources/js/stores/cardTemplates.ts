/*
 * Card-template registry store (custom-certs C2) — the uploaded PPTX/ODP
 * card designs, system + org scoped. Everything the server reports about a
 * template (card size, side count, ${keys}, fonts) was read from the file at
 * upload, so nothing here is user-entered except the name and description.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { realtimeTabId } from '@/echo';

export interface CardTemplateRow {
    id: string;
    name: string;
    description: string | null;
    original_filename: string;
    extension: 'pptx' | 'odp';
    size: number;
    /** Distinct ${key} names found in the file. */
    placeholders: string[];
    fonts: string[];
    /** Families LibreOffice would substitute — the card would re-flow. */
    unsupported_fonts: string[];
    slide_count: number;
    has_back: boolean;
    /** Points, read from the slide dimensions. */
    card_width: number;
    card_height: number;
    version: number;
    is_system: boolean;
    can_edit: boolean;
    can_delete: boolean;
    updated_at: string | null;
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

export const useCardTemplatesStore = defineStore('cardTemplates', () => {
    const library = ref<CardTemplateRow[]>([]);
    const loaded = ref(false);

    /** Templates asking for a font the converter cannot honour. */
    const withFontWarnings = computed(() =>
        library.value.filter((t) => t.unsupported_fonts.length > 0),
    );

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        await reload();
    }

    async function reload(): Promise<void> {
        const { data } = await axios.get<CardTemplateRow[]>(
            '/api/card-templates',
            { headers: defaultHeaders() },
        );
        library.value = data;
        loaded.value = true;
    }

    async function upload(
        file: File,
        name: string,
        description: string | null,
    ): Promise<CardTemplateRow> {
        const form = new FormData();
        form.append('file', file);
        form.append('name', name);

        if (description) {
            form.append('description', description);
        }

        const { data } = await axios.post<CardTemplateRow>(
            '/api/card-templates',
            form,
            { headers: defaultHeaders() },
        );
        library.value = [...library.value, data];

        return data;
    }

    async function replace(id: string, file: File): Promise<CardTemplateRow> {
        const form = new FormData();
        form.append('file', file);

        const { data } = await axios.post<CardTemplateRow>(
            `/api/card-templates/${id}/replace`,
            form,
            { headers: defaultHeaders() },
        );
        // The old version row is soft-deleted server-side and trainings are
        // re-pointed at this one.
        library.value = library.value.map((t) => (t.id === id ? data : t));

        return data;
    }

    async function rename(
        id: string,
        name: string,
        description: string | null,
    ): Promise<void> {
        const { data } = await axios.patch<CardTemplateRow>(
            `/api/card-templates/${id}`,
            { name, description },
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((t) => (t.id === id ? data : t));
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/card-templates/${id}`, {
            headers: defaultHeaders(),
        });
        library.value = library.value.filter((t) => t.id !== id);
    }

    return {
        library,
        withFontWarnings,
        loaded,
        load,
        reload,
        upload,
        replace,
        rename,
        destroy,
    };
});
