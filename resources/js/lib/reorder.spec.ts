import { describe, expect, it } from 'vitest';
import { moveItem } from '@/lib/reorder';

describe('moveItem', () => {
    it('moves an item down the list', () => {
        expect(moveItem(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a']);
    });

    it('moves an item up the list', () => {
        expect(moveItem(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b']);
    });

    it('lands on the target position as it stands after the removal', () => {
        // Drag semantics: "put this where that one is", so dropping b onto c
        // means b ends up where c was — not one short of it.
        expect(moveItem(['a', 'b', 'c'], 1, 2)).toEqual(['a', 'c', 'b']);
    });

    it('leaves the original array untouched', () => {
        const original = ['a', 'b', 'c'];

        moveItem(original, 0, 2);

        expect(original).toEqual(['a', 'b', 'c']);
    });

    it('does nothing when the item is dropped on itself', () => {
        expect(moveItem(['a', 'b', 'c'], 1, 1)).toEqual(['a', 'b', 'c']);
    });

    it('does nothing when an index is off the end', () => {
        // A guard, not a courtesy: callers pass indexes derived from drag
        // state and keyboard handlers, and a splice on -1 silently mangles
        // the list from the far end instead of failing.
        expect(moveItem(['a', 'b', 'c'], -1, 1)).toEqual(['a', 'b', 'c']);
        expect(moveItem(['a', 'b', 'c'], 0, 3)).toEqual(['a', 'b', 'c']);
        expect(moveItem(['a', 'b', 'c'], 3, 0)).toEqual(['a', 'b', 'c']);
    });

    it('handles a list too short to reorder', () => {
        expect(moveItem(['a'], 0, 0)).toEqual(['a']);
        expect(moveItem([], 0, 0)).toEqual([]);
    });
});
