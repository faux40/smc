/*
 * Tags store — library cache + per-morphable attached lists.
 *
 * Per the Pinia-relay principle: components never fetch tags or attach/detach
 * directly. The store owns the library cache, the per-morphable attached lists,
 * and the Reverb subscription that keeps everything in sync across peer tabs.
 *
 * The single TagsField.vue component talks only to this store; backend wiring
 * lives entirely here.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { useRealtime } from '@/composables/useRealtime';
import { realtimeTabId } from '@/echo';

export interface TagRow {
    id: string;
    name: string;
    color: string | null;
    // Optional override for the pill's text color. Null means derive
    // from `color` (the pre-feature default in TagPill).
    font_color: string | null;
    // How many morphable rows this tag is attached to across the org.
    // Hydrated by GET /api/tags and patched in place by TagAttached /
    // TagDetached broadcasts. Defaults to 0 for tags created in this
    // tab — the broadcast handler will reconcile.
    attached_count: number;
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

interface MorphableKey {
    type: string;
    id: string;
}

function keyOf(m: MorphableKey): string {
    return `${m.type}::${m.id}`;
}

export const useTagsStore = defineStore('tags', () => {
    const library = ref<TagRow[]>([]);
    const libraryLoaded = ref(false);

    // attached[morphableKey] = list of tag IDs currently attached.
    const attached = ref<Record<string, string[]>>({});

    const subscribedOrgId = ref<string | null>(null);

    async function loadLibrary(): Promise<void> {
        if (libraryLoaded.value) {
            return;
        }

        const { data } = await axios.get<TagRow[]>('/api/tags', {
            headers: defaultHeaders(),
        });
        library.value = data;
        libraryLoaded.value = true;
    }

    function libraryById(id: string): TagRow | undefined {
        return library.value.find((t) => t.id === id);
    }

    function attachedTagsFor(morphable: MorphableKey): TagRow[] {
        const ids = attached.value[keyOf(morphable)] ?? [];

        return ids
            .map((id) => libraryById(id))
            .filter((t): t is TagRow => t !== undefined);
    }

    function setAttached(morphable: MorphableKey, ids: string[]): void {
        attached.value = { ...attached.value, [keyOf(morphable)]: [...ids] };
    }

    async function create(
        name: string,
        color: string | null = null,
        fontColor: string | null = null,
    ): Promise<TagRow> {
        const { data } = await axios.post<TagRow>(
            '/api/tags',
            { name, color, font_color: fontColor },
            { headers: defaultHeaders() },
        );
        // POST /api/tags returns only id/name/color/font_color — backfill
        // the count so the library shape stays consistent.
        const row: TagRow = {
            ...data,
            attached_count: data.attached_count ?? 0,
        };
        library.value = [...library.value, row];

        return row;
    }

    async function rename(
        id: string,
        name: string,
        color: string | null = null,
        fontColor: string | null = null,
    ): Promise<void> {
        const { data } = await axios.patch<TagRow>(
            `/api/tags/${id}`,
            { name, color, font_color: fontColor },
            { headers: defaultHeaders() },
        );
        // PATCH returns id/name/color/font_color — preserve the
        // locally-tracked count.
        library.value = library.value.map((t) =>
            t.id === id
                ? { ...t, ...data, attached_count: t.attached_count }
                : t,
        );
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/tags/${id}`, { headers: defaultHeaders() });
        library.value = library.value.filter((t) => t.id !== id);
        // Strip the tag from every cached morphable's attached list.
        const next: Record<string, string[]> = {};

        for (const [key, ids] of Object.entries(attached.value)) {
            next[key] = ids.filter((tagId) => tagId !== id);
        }

        attached.value = next;
    }

    async function attach(
        morphable: MorphableKey,
        tagId: string,
    ): Promise<void> {
        await axios.post(
            '/api/tags/attach',
            {
                tag_id: tagId,
                taggable_type: morphable.type,
                taggable_id: morphable.id,
            },
            { headers: defaultHeaders() },
        );
        const key = keyOf(morphable);
        const cur = attached.value[key] ?? [];

        // Originating tab: bump locally because the broadcast self-echo is
        // filtered out (X-Origin-Tab). Peer tabs increment via the TagAttached
        // handler. Guard on the morphable-level idempotency since attach is
        // a syncWithoutDetaching on the server too.
        if (!cur.includes(tagId)) {
            attached.value = { ...attached.value, [key]: [...cur, tagId] };
            library.value = library.value.map((t) =>
                t.id === tagId
                    ? { ...t, attached_count: t.attached_count + 1 }
                    : t,
            );
        }
    }

    async function detach(
        morphable: MorphableKey,
        tagId: string,
    ): Promise<void> {
        await axios.post(
            '/api/tags/detach',
            {
                tag_id: tagId,
                taggable_type: morphable.type,
                taggable_id: morphable.id,
            },
            { headers: defaultHeaders() },
        );
        const key = keyOf(morphable);
        const cur = attached.value[key] ?? [];
        const wasAttached = cur.includes(tagId);
        attached.value = {
            ...attached.value,
            [key]: cur.filter((id) => id !== tagId),
        };

        if (wasAttached) {
            library.value = library.value.map((t) =>
                t.id === tagId
                    ? {
                          ...t,
                          attached_count: Math.max(0, t.attached_count - 1),
                      }
                    : t,
            );
        }
    }

    /**
     * Subscribe to peer-tab broadcasts on the org channel and patch the
     * caches in place. Self-echoes (origin_tab === own tab) are filtered
     * by useRealtime; we don't need to dedupe here.
     */
    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) {
            return;
        }

        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`, 'private', {
            persist: true,
        });

        bind(
            'TagCreated',
            (p: Partial<TagRow> & { id: string; name: string }) => {
                if (!library.value.some((t) => t.id === p.id)) {
                    library.value = [
                        ...library.value,
                        {
                            id: p.id,
                            name: p.name,
                            color: p.color ?? null,
                            font_color: p.font_color ?? null,
                            attached_count: p.attached_count ?? 0,
                        },
                    ];
                }
            },
        );
        bind(
            'TagUpdated',
            (p: Partial<TagRow> & { id: string; name: string }) => {
                library.value = library.value.map((t) =>
                    t.id === p.id
                        ? {
                              ...t,
                              name: p.name,
                              color: p.color ?? null,
                              font_color: p.font_color ?? null,
                          }
                        : t,
                );
            },
        );
        bind('TagDeleted', (p: { id: string }) => {
            library.value = library.value.filter((t) => t.id !== p.id);
            const next: Record<string, string[]> = {};

            for (const [k, ids] of Object.entries(attached.value)) {
                next[k] = ids.filter((id) => id !== p.id);
            }

            attached.value = next;
        });
        bind(
            'TagAttached',
            (p: {
                tag_id: string;
                taggable_type: string;
                taggable_id: string;
            }) => {
                const key = `${p.taggable_type}::${p.taggable_id}`;
                const cur = attached.value[key] ?? [];
                // Only bump the library count if the attach is actually new for
                // this morphable (idempotent attaches must not double-count).
                const newAttachment = !cur.includes(p.tag_id);

                if (newAttachment) {
                    attached.value = {
                        ...attached.value,
                        [key]: [...cur, p.tag_id],
                    };
                    library.value = library.value.map((t) =>
                        t.id === p.tag_id
                            ? { ...t, attached_count: t.attached_count + 1 }
                            : t,
                    );
                }
            },
        );
        bind(
            'TagDetached',
            (p: {
                tag_id: string;
                taggable_type: string;
                taggable_id: string;
            }) => {
                const key = `${p.taggable_type}::${p.taggable_id}`;
                const cur = attached.value[key] ?? [];
                const wasAttached = cur.includes(p.tag_id);
                attached.value = {
                    ...attached.value,
                    [key]: cur.filter((id) => id !== p.tag_id),
                };

                if (wasAttached) {
                    library.value = library.value.map((t) =>
                        t.id === p.tag_id
                            ? {
                                  ...t,
                                  attached_count: Math.max(
                                      0,
                                      t.attached_count - 1,
                                  ),
                              }
                            : t,
                    );
                }
            },
        );
    }

    const libraryCount = computed(() => library.value.length);

    return {
        library,
        libraryCount,
        libraryLoaded,
        attached,
        loadLibrary,
        libraryById,
        attachedTagsFor,
        setAttached,
        create,
        rename,
        destroy,
        attach,
        detach,
        subscribe,
    };
});
