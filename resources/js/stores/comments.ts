/*
 * Comments store — per-morphable list + Reverb subscription.
 *
 * Each morphable's comment list is cached separately under
 * `lists[morphable_type::morphable_id]`. Components call load(morphable)
 * once and then read reactively; peer broadcasts patch the list in place.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface CommentRow {
    id: string;
    commentable_type: string;
    commentable_id: string;
    author_id: string;
    author_name: string | null;
    parent_id: string | null;
    body: string;
    created_at: string | null;
    can_edit: boolean;
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

export const useCommentsStore = defineStore('comments', () => {
    const lists = ref<Record<string, CommentRow[]>>({});
    const loaded = ref<Record<string, boolean>>({});
    const subscribedOrgId = ref<string | null>(null);

    function listFor(morphable: MorphableKey): CommentRow[] {
        return lists.value[keyOf(morphable)] ?? [];
    }

    function setList(morphable: MorphableKey, rows: CommentRow[]): void {
        lists.value = { ...lists.value, [keyOf(morphable)]: rows };
    }

    async function load(morphable: MorphableKey): Promise<void> {
        if (loaded.value[keyOf(morphable)]) {
            return;
        }

        const { data } = await axios.get<CommentRow[]>('/api/comments', {
            headers: defaultHeaders(),
            params: {
                commentable_type: morphable.type,
                commentable_id: morphable.id,
            },
        });
        setList(morphable, data);
        loaded.value = { ...loaded.value, [keyOf(morphable)]: true };
    }

    async function create(
        morphable: MorphableKey,
        body: string,
        parentId: string | null = null,
    ): Promise<void> {
        await axios.post(
            '/api/comments',
            {
                commentable_type: morphable.type,
                commentable_id: morphable.id,
                parent_id: parentId,
                body,
            },
            { headers: defaultHeaders() },
        );
        // The server-side broadcast will patch the list via the subscription.
        // For the originating tab we re-fetch to pick up author_name + can_*.
        loaded.value = { ...loaded.value, [keyOf(morphable)]: false };
        await load(morphable);
    }

    async function update(id: string, body: string): Promise<void> {
        await axios.patch(
            `/api/comments/${id}`,
            { body },
            { headers: defaultHeaders() },
        );
        // Optimistic patch — the broadcast will confirm.
        const next: Record<string, CommentRow[]> = {};

        for (const [key, rows] of Object.entries(lists.value)) {
            next[key] = rows.map((c) => (c.id === id ? { ...c, body } : c));
        }

        lists.value = next;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/comments/${id}`, {
            headers: defaultHeaders(),
        });
        const next: Record<string, CommentRow[]> = {};

        for (const [key, rows] of Object.entries(lists.value)) {
            next[key] = rows.filter((c) => c.id !== id);
        }

        lists.value = next;
    }

    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind(
            'CommentCreated',
            (p: {
                id: string;
                commentable_type: string;
                commentable_id: string;
                author_id: string;
                parent_id: string | null;
                body: string;
            }) => {
                const key = `${p.commentable_type}::${p.commentable_id}`;
                const cur = lists.value[key];

                if (!cur) {
                    return;
                } // morphable not loaded by this tab — skip

                if (cur.some((c) => c.id === p.id)) {
                    return;
                }

                lists.value = {
                    ...lists.value,
                    [key]: [
                        ...cur,
                        {
                            id: p.id,
                            commentable_type: p.commentable_type,
                            commentable_id: p.commentable_id,
                            author_id: p.author_id,
                            author_name: null,
                            parent_id: p.parent_id,
                            body: p.body,
                            created_at: null,
                            can_edit: false,
                            can_delete: false,
                        },
                    ],
                };
            },
        );

        bind('CommentUpdated', (p: { id: string; body: string }) => {
            const next: Record<string, CommentRow[]> = {};

            for (const [key, rows] of Object.entries(lists.value)) {
                next[key] = rows.map((c) =>
                    c.id === p.id ? { ...c, body: p.body } : c,
                );
            }

            lists.value = next;
        });

        bind('CommentDeleted', (p: { id: string }) => {
            const next: Record<string, CommentRow[]> = {};

            for (const [key, rows] of Object.entries(lists.value)) {
                next[key] = rows.filter((c) => c.id !== p.id);
            }

            lists.value = next;
        });
    }

    return {
        lists,
        loaded,
        listFor,
        load,
        create,
        update,
        destroy,
        subscribe,
    };
});
