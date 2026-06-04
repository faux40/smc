import { computed, ref, type Ref } from 'vue';

/*
 * Client-side table sorting for in-memory row sets.
 *
 *   const { sorted, sortKey, sortDir, toggleSort } = useTableSort(
 *     () => store.users,
 *     { name: (u) => u.l_name, dept: (u) => u.department },
 *     { key: 'name', dir: 'asc' },
 *   );
 *
 * Click semantics match the existing tables: clicking the active column flips
 * direction; clicking a new column sorts it ascending. Empty/null values always
 * sort last regardless of direction. Numbers compare numerically, strings
 * case-insensitively. The source array is never mutated.
 */
export type SortDir = 'asc' | 'desc';

type Accessor<T> = (row: T) => string | number | null | undefined;

export function useTableSort<T>(
    rows: Ref<T[]> | (() => T[]),
    accessors: Record<string, Accessor<T>>,
    initial?: { key: string; dir?: SortDir },
) {
    const sortKey = ref<string | null>(initial?.key ?? null);
    const sortDir = ref<SortDir>(initial?.dir ?? 'asc');

    const getRows = typeof rows === 'function' ? rows : () => rows.value;

    function toggleSort(key: string): void {
        if (sortKey.value === key) {
            sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortDir.value = 'asc';
        }
    }

    const isEmpty = (v: unknown): boolean =>
        v === null || v === undefined || v === '';

    const sorted = computed<T[]>(() => {
        const key = sortKey.value;
        const list = [...getRows()];
        const accessor = key ? accessors[key] : undefined;

        if (!accessor) {
            return list;
        }

        const dir = sortDir.value === 'asc' ? 1 : -1;

        return list.sort((a, b) => {
            const av = accessor(a);
            const bv = accessor(b);
            const ae = isEmpty(av);
            const be = isEmpty(bv);

            // Empties always last, independent of direction.
            if (ae && be) return 0;
            if (ae) return 1;
            if (be) return -1;

            const base =
                typeof av === 'number' && typeof bv === 'number'
                    ? av - bv
                    : String(av).localeCompare(String(bv), undefined, {
                          sensitivity: 'base',
                      });

            return base * dir;
        });
    });

    return { sortKey, sortDir, toggleSort, sorted };
}
