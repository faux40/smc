import { ref } from 'vue';
import { describe, expect, it } from 'vitest';
import { useTableSort } from '@/composables/useTableSort';

interface Row {
    name: string;
    dept: string | null;
    hours: number | null;
}

const rows: Row[] = [
    { name: 'Bravo', dept: 'Ops', hours: 2 },
    { name: 'alpha', dept: null, hours: 10 },
    { name: 'Charlie', dept: 'Admin', hours: null },
];

const accessors = {
    name: (r: Row) => r.name,
    dept: (r: Row) => r.dept,
    hours: (r: Row) => r.hours,
};

describe('useTableSort', () => {
    it('returns rows unsorted when no key is active', () => {
        const { sorted } = useTableSort(ref(rows), accessors);
        expect(sorted.value.map((r) => r.name)).toEqual([
            'Bravo',
            'alpha',
            'Charlie',
        ]);
    });

    it('sorts case-insensitively ascending on the active key', () => {
        const { sorted, toggleSort } = useTableSort(ref(rows), accessors);
        toggleSort('name');
        expect(sorted.value.map((r) => r.name)).toEqual([
            'alpha',
            'Bravo',
            'Charlie',
        ]);
    });

    it('flips to descending on a second toggle of the same key', () => {
        const { sorted, toggleSort, sortDir } = useTableSort(
            ref(rows),
            accessors,
        );
        toggleSort('name');
        toggleSort('name');
        expect(sortDir.value).toBe('desc');
        expect(sorted.value.map((r) => r.name)).toEqual([
            'Charlie',
            'Bravo',
            'alpha',
        ]);
    });

    it('resets to ascending when switching to a different key', () => {
        const { sorted, toggleSort, sortDir, sortKey } = useTableSort(
            ref(rows),
            accessors,
        );
        toggleSort('name');
        toggleSort('name'); // desc
        toggleSort('dept'); // new key → asc
        expect(sortKey.value).toBe('dept');
        expect(sortDir.value).toBe('asc');
    });

    it('sorts numbers numerically, not lexically', () => {
        const { sorted, toggleSort } = useTableSort(ref(rows), accessors);
        toggleSort('hours');
        // 2, 10, then null last — numeric order (not "10" < "2").
        expect(sorted.value.map((r) => r.hours)).toEqual([2, 10, null]);
    });

    it('always sorts empty/null values last, in both directions', () => {
        const { sorted, toggleSort } = useTableSort(ref(rows), accessors);
        toggleSort('dept'); // asc
        expect(sorted.value.map((r) => r.dept)).toEqual(['Admin', 'Ops', null]);
        toggleSort('dept'); // desc
        expect(sorted.value.map((r) => r.dept)).toEqual(['Ops', 'Admin', null]);
    });

    it('accepts an initial sort', () => {
        const { sortKey, sortDir, sorted } = useTableSort(ref(rows), accessors, {
            key: 'name',
            dir: 'asc',
        });
        expect(sortKey.value).toBe('name');
        expect(sortDir.value).toBe('asc');
        expect(sorted.value[0].name).toBe('alpha');
    });

    it('does not mutate the source array', () => {
        const source = ref(rows);
        const { toggleSort } = useTableSort(source, accessors);
        toggleSort('name');
        expect(source.value.map((r) => r.name)).toEqual([
            'Bravo',
            'alpha',
            'Charlie',
        ]);
    });
});
