import { computed, ref, type Ref } from 'vue';

/**
 * Keyed multi-row selection for tables — survives paging/filtering (the picked
 * rows are held by key, not by page index). The key function lets callers make
 * rows unique by more than one field (e.g. a requirement lists one row per
 * user *and* training, so the key is user+training, not just user).
 */
export function useRowSelection<T>(keyOf: (item: T) => string) {
    const selected = ref(new Map<string, T>()) as Ref<Map<string, T>>;

    const count = computed(() => selected.value.size);
    const items = computed(() => [...selected.value.values()]);

    function isSelected(item: T): boolean {
        return selected.value.has(keyOf(item));
    }

    function toggle(item: T): void {
        const next = new Map(selected.value);
        const key = keyOf(item);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.set(key, item);
        }
        selected.value = next;
    }

    function allOnPage(rows: T[]): boolean {
        return rows.length > 0 && rows.every((r) => selected.value.has(keyOf(r)));
    }

    function toggleAllOnPage(rows: T[]): void {
        const next = new Map(selected.value);
        if (allOnPage(rows)) {
            rows.forEach((r) => next.delete(keyOf(r)));
        } else {
            rows.forEach((r) => next.set(keyOf(r), r));
        }
        selected.value = next;
    }

    function clear(): void {
        selected.value = new Map();
    }

    return {
        selected,
        count,
        items,
        isSelected,
        toggle,
        allOnPage,
        toggleAllOnPage,
        clear,
    };
}
