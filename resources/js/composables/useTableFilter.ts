import { reactive } from 'vue';

/*
 * Session-scoped table filtering.
 *
 *   const filter = useTableFilter('users', initialFromUrl, BLANK, (p) =>
 *     router.get('/users', toQuery(p), { preserveState: true, replace: true }),
 *   );
 *
 * - `params` is the reactive filter state (bind inputs to it).
 * - `commit()` applies the params to the server (the caller's `apply`, which
 *   owns the router.get / query-string shape) AND mirrors them to
 *   sessionStorage — so the filter survives navigation/reload within the tab
 *   session but is NEVER written to the user's saved profile.
 * - `restore(isDefault)` re-applies the session filter on load (call once on
 *   mount): when the page arrived without filter params in the URL, it
 *   re-applies the session's last filter so every visit stays filtered.
 * - `clear()` resets to the blank defaults, drops the session entry, and
 *   re-queries unfiltered (wire this to a visible "Clear" control).
 *
 * Sorting is NOT here — it stays client-side via useTableSort.
 */
const STORAGE_PREFIX = 'tableFilters:';

function sessionRead(viewId: string): Record<string, unknown> | null {
    try {
        const raw = sessionStorage.getItem(STORAGE_PREFIX + viewId);

        return raw ? (JSON.parse(raw) as Record<string, unknown>) : null;
    } catch {
        return null;
    }
}

function sessionWrite(viewId: string, value: Record<string, unknown>): void {
    try {
        sessionStorage.setItem(STORAGE_PREFIX + viewId, JSON.stringify(value));
    } catch {
        /* storage unavailable / quota — filtering still works, just not sticky */
    }
}

function sessionClearKey(viewId: string): void {
    try {
        sessionStorage.removeItem(STORAGE_PREFIX + viewId);
    } catch {
        /* ignore */
    }
}

export function useTableFilter<T extends Record<string, unknown>>(
    viewId: string,
    initial: T,
    blank: T,
    apply: (params: T) => void,
) {
    const params = reactive(clone(initial)) as T;

    function snapshot(): Record<string, unknown> {
        return clone(params) as Record<string, unknown>;
    }

    // Apply to the server now (caller's router.get) + remember for the session.
    function commit(): void {
        apply(snapshot() as T);
        sessionWrite(viewId, snapshot());
    }

    function restore(isDefault: boolean): void {
        const saved = sessionRead(viewId);

        if (isDefault && saved && Object.keys(saved).length > 0) {
            Object.assign(params, saved);
            apply(snapshot() as T);
        }
    }

    function clear(): void {
        Object.assign(params, clone(blank));
        sessionClearKey(viewId);
        apply(snapshot() as T);
    }

    return { params, commit, restore, clear };
}

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}
