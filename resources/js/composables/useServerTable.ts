import { useDebounceFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { SortDir } from '@/composables/useTableSort';

/*
 * Server-side table state: page / per-page / sort / dir / search, plus the
 * rows + paging meta the server returns. The caller supplies a `fetcher`
 * (the store relay's axios call) and owns nothing else — changing any param
 * refetches. `refetchSoon()` is for realtime: a Reverb broadcast just
 * re-pulls the current page (debounced) rather than patching it, so the page
 * stays consistent with the server's sort/filter/paging.
 *
 * Pairs with the paginated `{ data, meta }` API contract (see
 * CompletionsController::index). Columns/visibility stay in useTableView.
 */
export interface ServerTableMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ServerTableResponse<T> {
    data: T[];
    meta: ServerTableMeta;
}

export interface ServerTableQuery {
    page: number;
    per_page: number;
    sort: string | null;
    dir: SortDir;
    q: string;
}

export function useServerTable<T>(
    fetcher: (params: ServerTableQuery) => Promise<ServerTableResponse<T>>,
    opts: { perPage?: number; sort?: string | null; dir?: SortDir } = {},
) {
    const rows = ref<T[]>([]) as { value: T[] };
    const page = ref(1);
    const perPage = ref(opts.perPage ?? 25);
    const sort = ref<string | null>(opts.sort ?? null);
    const dir = ref<SortDir>(opts.dir ?? 'desc');
    const q = ref('');
    const total = ref(0);
    const lastPage = ref(1);
    const loading = ref(false);

    async function fetchPage(): Promise<void> {
        loading.value = true;

        try {
            const res = await fetcher({
                page: page.value,
                per_page: perPage.value,
                sort: sort.value,
                dir: dir.value,
                q: q.value,
            });
            rows.value = res.data;
            total.value = res.meta.total;
            lastPage.value = Math.max(1, res.meta.last_page);
            page.value = res.meta.current_page; // server clamps; mirror it back
        } finally {
            loading.value = false;
        }
    }

    function setPage(p: number): void {
        const next = Math.min(Math.max(1, p), lastPage.value);

        if (next === page.value) {
            return;
        }

        page.value = next;
        void fetchPage();
    }

    function setPerPage(n: number): void {
        perPage.value = n;
        page.value = 1;
        void fetchPage();
    }

    // Click a header: same key flips direction, a new key starts ascending.
    function setSort(key: string): void {
        if (sort.value === key) {
            dir.value = dir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sort.value = key;
            dir.value = 'asc';
        }

        page.value = 1;
        void fetchPage();
    }

    const setQuery = useDebounceFn((value: string): void => {
        q.value = value;
        page.value = 1;
        void fetchPage();
    }, 300);

    // Realtime: re-pull the current page after a broadcast (debounced so a
    // burst of events collapses into one refetch).
    const refetchSoon = useDebounceFn((): void => {
        void fetchPage();
    }, 400);

    return {
        rows,
        page,
        perPage,
        sort,
        dir,
        q,
        total,
        lastPage,
        loading,
        hasPrev: computed(() => page.value > 1),
        hasNext: computed(() => page.value < lastPage.value),
        fetchPage,
        setPage,
        setPerPage,
        setSort,
        setQuery,
        refetchSoon,
    };
}
