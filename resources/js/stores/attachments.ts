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
    mime: string | null;
    size: number | null;
    uploaded_by_user_id: string;
    uploaded_by_name: string | null;
    created_at: string | null;
    can_delete: boolean;
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

    async function upload(morphable: MorphableKey, file: File): Promise<void> {
        const fd = new FormData();
        fd.append('attachable_type', morphable.type);
        fd.append('attachable_id', morphable.id);
        fd.append('file', file);
        await axios.post('/api/attachments', fd, { headers: defaultHeaders() });
        // Reload to pick up can_delete + uploader_name + timestamps.
        loaded.value = { ...loaded.value, [keyOf(morphable)]: false };
        await load(morphable);
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

        const { bind } = useRealtime(`org.${orgId}`);

        bind(
            'AttachmentCreated',
            (p: {
                id: string;
                attachable_type: string;
                attachable_id: string;
                filename: string;
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
                            mime: p.mime,
                            size: p.size,
                            uploaded_by_user_id: p.uploaded_by_user_id,
                            uploaded_by_name: null,
                            created_at: null,
                            can_delete: false,
                        },
                        ...cur,
                    ],
                };
            },
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
        listFor,
        load,
        upload,
        destroy,
        downloadUrl,
        viewUrl,
        subscribe,
    };
});
