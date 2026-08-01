/*
 * Attachments store — per-morphable list + Reverb subscription.
 *
 * Mirrors the Comments store shape. Components call load(morphable) once
 * and then read reactively; uploads + deletes route through the store.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface AttachmentRow {
    id: string;
    attachable_type: string;
    attachable_id: string;
    filename: string;
    // Optional uploader metadata: a free-text org vocabulary "type"
    // (e.g. "Sign-in sheet") + a freeform description.
    type: string | null;
    description: string | null;
    mime: string | null;
    size: number | null;
    uploaded_by_user_id: string;
    uploaded_by_name: string | null;
    created_at: string | null;
    can_delete: boolean;
    can_edit: boolean;
}

/** Optional metadata supplied when uploading. */
export interface AttachmentInfo {
    type?: string | null;
    description?: string | null;
}

interface MorphableKey {
    type: string;
    id: string;
}

function keyOf(m: MorphableKey): string {
    return `${m.type}::${m.id}`;
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

export const useAttachmentsStore = defineStore('attachments', () => {
    const lists = ref<Record<string, AttachmentRow[]>>({});
    const loaded = ref<Record<string, boolean>>({});
    const subscribedOrgId = ref<string | null>(null);

    // Org-scoped vocabulary of previously-used attachment types, cached for the
    // upload form's type-ahead. One fetch per session unless invalidated.
    const types = ref<string[]>([]);
    const typesLoaded = ref(false);

    async function loadTypes(force = false): Promise<void> {
        if (typesLoaded.value && !force) {
            return;
        }

        const { data } = await axios.get<string[]>('/api/attachments/types', {
            headers: defaultHeaders(),
        });
        types.value = data;
        typesLoaded.value = true;
    }

    function invalidateTypes(): void {
        typesLoaded.value = false;
    }

    function listFor(morphable: MorphableKey): AttachmentRow[] {
        return lists.value[keyOf(morphable)] ?? [];
    }

    function setList(morphable: MorphableKey, rows: AttachmentRow[]): void {
        lists.value = { ...lists.value, [keyOf(morphable)]: rows };
    }

    async function load(morphable: MorphableKey): Promise<void> {
        if (loaded.value[keyOf(morphable)]) {
            return;
        }

        const { data } = await axios.get<AttachmentRow[]>('/api/attachments', {
            headers: defaultHeaders(),
            params: {
                attachable_type: morphable.type,
                attachable_id: morphable.id,
            },
        });
        setList(morphable, data);
        loaded.value = { ...loaded.value, [keyOf(morphable)]: true };
    }

    async function upload(
        morphable: MorphableKey,
        file: File,
        info: AttachmentInfo = {},
    ): Promise<void> {
        const fd = new FormData();
        fd.append('attachable_type', morphable.type);
        fd.append('attachable_id', morphable.id);
        fd.append('file', file);

        if (info.type) {
            fd.append('type', info.type);
        }

        if (info.description) {
            fd.append('description', info.description);
        }

        await axios.post('/api/attachments', fd, { headers: defaultHeaders() });
        // A new type may have been introduced — refresh the vocabulary next open.
        invalidateTypes();
        // Reload to pick up can_delete + uploader_name + timestamps.
        loaded.value = { ...loaded.value, [keyOf(morphable)]: false };
        await load(morphable);
    }

    /**
     * Render + file a copy of a generated class document ('certificates' or
     * 'summary') as a TrainingClass attachment, then refresh that class's
     * list so the new file shows with full metadata (name/uploader/timestamp).
     */
    async function fileClassDocument(
        classId: string,
        kind: 'certificates' | 'summary' | 'sign-in',
        info: AttachmentInfo = {},
    ): Promise<void> {
        const path = kind === 'sign-in' ? 'sign-in-sheet' : kind;
        await axios.post(
            `/api/classes/${classId}/${path}`,
            {
                type: info.type || null,
                description: info.description || null,
            },
            { headers: defaultHeaders() },
        );
        // A new type may have been introduced — refresh the vocabulary next open.
        invalidateTypes();
        const morphable = { type: 'App\\Models\\TrainingClass', id: classId };
        loaded.value = { ...loaded.value, [keyOf(morphable)]: false };
        await load(morphable);
    }

    /** Edit an attachment's Type + Description (gated server-side). */
    async function updateInfo(id: string, info: AttachmentInfo): Promise<void> {
        const { data } = await axios.patch<{
            id: string;
            type: string | null;
            description: string | null;
        }>(
            `/api/attachments/${id}`,
            {
                type: info.type || null,
                description: info.description || null,
            },
            { headers: defaultHeaders() },
        );
        patchRow(id, { type: data.type, description: data.description });
        // An edit can introduce a new type — refresh the vocabulary next open.
        invalidateTypes();
    }

    /** Patch matching cached rows across all loaded lists. */
    function patchRow(id: string, fields: Partial<AttachmentRow>): void {
        const next: Record<string, AttachmentRow[]> = {};

        for (const [key, rows] of Object.entries(lists.value)) {
            next[key] = rows.map((a) =>
                a.id === id ? { ...a, ...fields } : a,
            );
        }

        lists.value = next;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/attachments/${id}`, {
            headers: defaultHeaders(),
        });
        const next: Record<string, AttachmentRow[]> = {};

        for (const [key, rows] of Object.entries(lists.value)) {
            next[key] = rows.filter((a) => a.id !== id);
        }

        lists.value = next;
    }

    function downloadUrl(id: string): string {
        return `/api/attachments/${id}/download`;
    }

    function viewUrl(id: string): string {
        return `/api/attachments/${id}/view`;
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
            'AttachmentCreated',
            (p: {
                id: string;
                attachable_type: string;
                attachable_id: string;
                filename: string;
                type: string | null;
                description: string | null;
                mime: string | null;
                size: number | null;
                uploaded_by_user_id: string;
            }) => {
                const key = `${p.attachable_type}::${p.attachable_id}`;
                const cur = lists.value[key];

                if (!cur) {
                    return;
                }

                if (cur.some((a) => a.id === p.id)) {
                    return;
                }

                lists.value = {
                    ...lists.value,
                    [key]: [
                        {
                            id: p.id,
                            attachable_type: p.attachable_type,
                            attachable_id: p.attachable_id,
                            filename: p.filename,
                            type: p.type,
                            description: p.description,
                            mime: p.mime,
                            size: p.size,
                            uploaded_by_user_id: p.uploaded_by_user_id,
                            uploaded_by_name: null,
                            created_at: null,
                            can_delete: false,
                            can_edit: false,
                        },
                        ...cur,
                    ],
                };
            },
        );

        bind(
            'AttachmentUpdated',
            (p: {
                id: string;
                type: string | null;
                description: string | null;
            }) => patchRow(p.id, { type: p.type, description: p.description }),
        );

        bind('AttachmentDeleted', (p: { id: string }) => {
            const next: Record<string, AttachmentRow[]> = {};

            for (const [key, rows] of Object.entries(lists.value)) {
                next[key] = rows.filter((a) => a.id !== p.id);
            }

            lists.value = next;
        });
    }

    return {
        lists,
        loaded,
        types,
        listFor,
        load,
        loadTypes,
        invalidateTypes,
        upload,
        fileClassDocument,
        updateInfo,
        destroy,
        downloadUrl,
        viewUrl,
        subscribe,
    };
});
