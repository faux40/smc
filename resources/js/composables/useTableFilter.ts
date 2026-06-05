import { reactive } from 'vue';
import { usePreferencesStore } from '@/stores/preferences';

/*
 * Server-side table filtering, relayed through the prefs store.
 *
 *   const filter = useTableFilter('users', { q: '', role: '' }, (p) =>
 *     router.get('/users', toQuery(p), { preserveState: true, replace: true }),
 *   );
 *
 * - `params` is the reactive filter state (bind inputs to it).
 * - `commit()` applies the params to the server (the caller's `apply`, which
 *   owns the router.get / query-string shape) AND persists them to the user's
 *   prefs (debounced) so the view is remembered.
 * - `restoreSaved(isDefault)` re-applies the saved filters on a clean visit
 *   (call once on mount, after the prefs store is hydrated): when the page
 *   loaded unfiltered, it restores the user's last filters.
 *
 * Sorting is NOT here — it stays client-side via useTableSort.
 */
export function useTableFilter<T extends Record<string, unknown>>(
    viewId: string,
    initial: T,
    apply: (params: T) => void,
) {
    const prefs = usePreferencesStore();
    const params = reactive(structuredCloneSafe(initial)) as T;

    function snapshot(): Record<string, unknown> {
        return JSON.parse(JSON.stringify(params));
    }

    // Apply to the server now (caller's router.get) + save to prefs. The prefs
    // store debounces the actual PATCH, so no extra debounce here.
    function commit(): void {
        apply(snapshot() as T);
        prefs.update(viewId, { filters: snapshot() });
    }

    function restoreSaved(isDefault: boolean): void {
        const saved = prefs.view(viewId).filters;

        if (isDefault && saved && Object.keys(saved).length > 0) {
            Object.assign(params, saved);
            apply(snapshot() as T);
        }
    }

    return { params, commit, restoreSaved };
}

function structuredCloneSafe<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}
