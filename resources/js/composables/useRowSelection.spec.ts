import { describe, expect, it } from 'vitest';
import { useRowSelection } from '@/composables/useRowSelection';

interface Row {
    user: string;
    training: string;
}
const key = (r: Row) => `${r.user}::${r.training}`;

describe('useRowSelection', () => {
    it('toggles rows on and off and reports count + items', () => {
        const s = useRowSelection<Row>(key);
        const a = { user: 'u1', training: 't1' };
        const b = { user: 'u1', training: 't2' };

        s.toggle(a);
        s.toggle(b);
        expect(s.count.value).toBe(2);
        expect(s.isSelected(a)).toBe(true);
        // Same user, different training → a distinct selection.
        expect(s.items.value).toEqual([a, b]);

        s.toggle(a);
        expect(s.count.value).toBe(1);
        expect(s.isSelected(a)).toBe(false);
    });

    it('selects and clears a whole page, and clears everything', () => {
        const s = useRowSelection<Row>(key);
        const page = [
            { user: 'u1', training: 't1' },
            { user: 'u2', training: 't1' },
        ];

        expect(s.allOnPage(page)).toBe(false);
        s.toggleAllOnPage(page);
        expect(s.allOnPage(page)).toBe(true);
        expect(s.count.value).toBe(2);

        s.toggleAllOnPage(page);
        expect(s.count.value).toBe(0);

        s.toggleAllOnPage(page);
        s.clear();
        expect(s.count.value).toBe(0);
    });
});
