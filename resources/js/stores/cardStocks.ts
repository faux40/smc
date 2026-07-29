/*
 * Card-stock registry store (custom-certs C2) — the printable geometry of
 * purchased card sheets, system + org scoped. Every measurement is in points
 * (1/72in), matching the API; the editor converts for entry via
 * lib/cardGeometry.
 */

import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { realtimeTabId } from '@/echo';
import type { CardGrid } from '@/lib/cardGeometry';

export interface CardStockRow extends CardGrid {
    id: string;
    name: string;
    duplex_flip: 'long_edge' | 'short_edge' | null;
    notes: string | null;
    /** Derived server-side from the grid — never re-computed for display. */
    per_sheet: number;
    is_system: boolean;
    can_edit: boolean;
    can_delete: boolean;
}

/** Everything a save sends; the server fills org_id and rejects a bad grid. */
export type CardStockPayload = Partial<
    Omit<
        CardStockRow,
        'id' | 'per_sheet' | 'is_system' | 'can_edit' | 'can_delete'
    >
>;

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

export const useCardStocksStore = defineStore('cardStocks', () => {
    const library = ref<CardStockRow[]>([]);
    const loaded = ref(false);

    /**
     * The org's own stocks. System stocks stay in `library` — they are
     * pickable when printing — but only these can be edited.
     */
    const editable = computed(() => library.value.filter((s) => s.can_edit));

    async function load(): Promise<void> {
        if (loaded.value) {
            return;
        }

        await reload();
    }

    async function reload(): Promise<void> {
        const { data } = await axios.get<CardStockRow[]>('/api/card-stocks', {
            headers: defaultHeaders(),
        });
        library.value = data;
        loaded.value = true;
    }

    async function create(payload: CardStockPayload): Promise<CardStockRow> {
        const { data } = await axios.post<CardStockRow>(
            '/api/card-stocks',
            payload,
            { headers: defaultHeaders() },
        );
        library.value = [...library.value, data];

        return data;
    }

    async function update(
        id: string,
        payload: CardStockPayload,
    ): Promise<CardStockRow> {
        const { data } = await axios.patch<CardStockRow>(
            `/api/card-stocks/${id}`,
            payload,
            { headers: defaultHeaders() },
        );
        library.value = library.value.map((s) => (s.id === id ? data : s));

        return data;
    }

    async function destroy(id: string): Promise<void> {
        await axios.delete(`/api/card-stocks/${id}`, {
            headers: defaultHeaders(),
        });
        library.value = library.value.filter((s) => s.id !== id);
    }

    return { library, editable, loaded, load, reload, create, update, destroy };
});
