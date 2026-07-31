/*
 * The built-in `${key}` catalogue a card design draws from (custom-certs C4e).
 *
 * Fetched rather than hard-coded: the server derives it from the same constant
 * the merge reads, so a key listed here is a key that resolves. A copy kept in
 * the client would be one release away from promising a key that prints as
 * literal text on purchased stock.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { realtimeTabId } from '@/echo';

export interface MergeKey {
    key: string;
    /** What the author types into the slide: `${key}`. */
    placeholder: string;
}

export interface MergeKeyGroup {
    group: string;
    keys: MergeKey[];
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

export const useCardMergeKeysStore = defineStore('cardMergeKeys', () => {
    const groups = ref<MergeKeyGroup[]>([]);
    const loaded = ref(false);

    /** A fixed vocabulary — it only changes with a deploy. */
    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        const { data } = await axios.get<MergeKeyGroup[]>(
            '/api/card-merge-keys',
            { headers: defaultHeaders() },
        );

        groups.value = data;
        loaded.value = true;
    }

    return { groups, loaded, load };
});
