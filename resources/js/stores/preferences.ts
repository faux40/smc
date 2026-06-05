/*
 * Per-user UI preferences relay.
 *
 * Holds the single `users.preferences` JSON blob (hydrated once from the
 * shared `auth.user.preferences` prop), keyed by view id, and PATCHes it back
 * — debounced — whenever a view's columns or filters change. This is NOT a
 * mirror of the DB: it's only the prefs blob the frontend owns. Components read
 * + write through `useTableView(viewId)`, never the endpoint directly.
 *
 * Shape (per view id, e.g. 'users' / 'assignments'):
 *   { filters: {...}, visible_columns: { colKey: bool }, column_order: [colKey] }
 */
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { realtimeTabId } from '@/echo';

export interface ViewPrefs {
    filters?: Record<string, unknown>;
    visible_columns?: Record<string, boolean>;
    column_order?: string[];
}

export type PrefsBlob = Record<string, ViewPrefs>;

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

export const usePreferencesStore = defineStore('preferences', () => {
    const prefs = ref<PrefsBlob>({});

    function hydrate(initial: PrefsBlob | null | undefined): void {
        prefs.value = initial ? { ...initial } : {};
    }

    function view(viewId: string): ViewPrefs {
        return prefs.value[viewId] ?? {};
    }

    const persist = useDebounceFn(
        () =>
            axios.patch(
                '/api/me/preferences',
                { preferences: prefs.value },
                { headers: defaultHeaders() },
            ),
        600,
    );

    // Shallow-merge `partial` into the view's prefs (so toggling columns doesn't
    // wipe saved filters, and one view never clobbers another), then persist.
    function update(viewId: string, partial: ViewPrefs): void {
        prefs.value = {
            ...prefs.value,
            [viewId]: { ...(prefs.value[viewId] ?? {}), ...partial },
        };
        void persist();
    }

    return { prefs, hydrate, view, update };
});
