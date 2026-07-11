/*
 * Doc-template registry store (Phase D2) — system + org DOCX/ODT master
 * templates. Upload/replace go up as multipart; the server extracts
 * ${key} placeholders and auto-registers unknown keys as draft merge
 * fields (so a peer MergeFieldsChanged may follow an upload).
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface DocTemplateRow {
    id: string;
    name: string;
    description: string | null;
    original_filename: string;
    extension: 'docx' | 'odt';
    size: number;
    placeholders: string[];
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

export const useDocTemplatesStore = defineStore('docTemplates', () => {
    const library = ref<DocTemplateRow[]>([]);
    const loaded = ref(false);
    const subscribedOrgId = ref<string | null>(null);

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        await reload();
    }

    async function reload(): Promise<void> {
        const { data } = await axios.get<DocTemplateRow[]>('/api/doc-templates', {
            headers: defaultHeaders(),
        });
        library.value = data;
        loaded.value = true;
    }

    async function upload(
        file: File,
        name: string,
        description: string | null,
    ): Promise<DocTemplateRow> {
        const form = new FormData();
        form.append('file', file);
        form.append('name', name);

        if (description) {
            form.append('description', description);
        }

        const { data } = await axios.post<DocTemplateRow>('/api/doc-templates', form, {
            headers: defaultHeaders(),
        });
        library.value = [...library.value, data];

        return data;
    }

    async function replace(id: string, file: File): Promise<DocTemplateRow> {
        const form = new FormData();
        form.append('file', file);

        const { data } = await axios.post<DocTemplateRow>(
            `/api/doc-templates/${id}/replace`,
            form,
            { headers: defaultHeaders() },
        );
        // The old version row is soft-deleted server-side.
        library.value = library.value.map((t) => (t.id === id ? data : t));

        return data;
    }

    async function rename(
        id: string,
        name: string,
        description: string | null,
    ): Promise<void> {
        const { data } = await axios.patch<DocTemplateRow>(
            `/api/doc-templates/${id}`,
            { name, description },
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((t) => (t.id === id ? data : t));
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/doc-templates/${id}`, { headers: defaultHeaders() });
        library.value = library.value.filter((t) => t.id !== id);
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);
        bind('DocTemplatesChanged', (p: { origin_tab?: string | null }) => {
            if (p.origin_tab === realtimeTabId()) {
                return;
            }

            void reload();
        });
    }

    return { library, loaded, load, reload, upload, replace, rename, destroy, subscribe };
});
