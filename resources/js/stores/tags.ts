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
import { realtimeTabId } from '@/echo';
import { useRealtime } from '@/composables/useRealtime';

export interface TagRow {
    id: string;
    name: string;
    color: string | null;
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
        if (libraryLoaded.value) return;
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

    async function create(name: string, color: string | null = null): Promise<TagRow> {
        const { data } = await axios.post<TagRow>(
            '/api/tags',
            { name, color },
            { headers: defaultHeaders() },
        );
        library.value = [...library.value, data];
        return data;
    }

    async function rename(id: string, name: string, color: string | null = null): Promise<void> {
        const { data } = await axios.patch<TagRow>(
            `/api/tags/${id}`,
            { name, color },
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((t) => (t.id === id ? data : t));
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

    async function attach(morphable: MorphableKey, tagId: string): Promise<void> {
        await axios.post(
            '/api/tags/attach',
            { tag_id: tagId, taggable_type: morphable.type, taggable_id: morphable.id },
            { headers: defaultHeaders() },
        );
        const key = keyOf(morphable);
        const cur = attached.value[key] ?? [];
        if (!cur.includes(tagId)) {
            attached.value = { ...attached.value, [key]: [...cur, tagId] };
        }
    }

    async function detach(morphable: MorphableKey, tagId: string): Promise<void> {
        await axios.post(
            '/api/tags/detach',
            { tag_id: tagId, taggable_type: morphable.type, taggable_id: morphable.id },
            { headers: defaultHeaders() },
        );
        const key = keyOf(morphable);
        const cur = attached.value[key] ?? [];
        attached.value = { ...attached.value, [key]: cur.filter((id) => id !== tagId) };
    }

    /**
     * Subscribe to peer-tab broadcasts on the org channel and patch the
     * caches in place. Self-echoes (origin_tab === own tab) are filtered
     * by useRealtime; we don't need to dedupe here.
     */
    function subscribe(orgId: string): void {
        if (subscribedOrgId.value === orgId) return;
        subscribedOrgId.value = orgId;

        const { bind } = useRealtime(`org.${orgId}`);

        bind('TagCreated', (p: TagRow) => {
            if (!library.value.some((t) => t.id === p.id)) {
                library.value = [...library.value, p];
            }
        });
        bind('TagUpdated', (p: TagRow) => {
            library.value = library.value.map((t) => (t.id === p.id ? p : t));
        });
        bind('TagDeleted', (p: { id: string }) => {
            library.value = library.value.filter((t) => t.id !== p.id);
            const next: Record<string, string[]> = {};
            for (const [k, ids] of Object.entries(attached.value)) {
                next[k] = ids.filter((id) => id !== p.id);
            }
            attached.value = next;
        });
        bind('TagAttached', (p: { tag_id: string; taggable_type: string; taggable_id: string }) => {
            const key = `${p.taggable_type}::${p.taggable_id}`;
            const cur = attached.value[key] ?? [];
            if (!cur.includes(p.tag_id)) {
                attached.value = { ...attached.value, [key]: [...cur, p.tag_id] };
            }
        });
        bind('TagDetached', (p: { tag_id: string; taggable_type: string; taggable_id: string }) => {
            const key = `${p.taggable_type}::${p.taggable_id}`;
            const cur = attached.value[key] ?? [];
            attached.value = { ...attached.value, [key]: cur.filter((id) => id !== p.tag_id) };
        });
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
