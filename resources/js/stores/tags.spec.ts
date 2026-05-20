import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useTagsStore } from '@/stores/tags';
import type { TagRow } from '@/stores/tags';

/*
 * First real spec for the frontend harness. The tags store is the data-relay
 * backbone for every tag surface, so its in-memory cache logic is worth
 * locking down. These cover the network-free paths (lookup, id→row mapping,
 * count); axios/Reverb-driven paths get their own specs as we touch them.
 *
 * Note: characterization of existing behavior — the store predates the
 * harness. New store behavior follows strict red→green from here.
 */
function tag(overrides: Partial<TagRow> & { id: string }): TagRow {
    return {
        name: overrides.id,
        color: null,
        font_color: null,
        attached_count: 0,
        ...overrides,
    };
}

const USER = { type: 'user', id: 'u1' };

describe('useTagsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    describe('libraryById', () => {
        it('returns the matching tag', () => {
            const store = useTagsStore();
            store.library = [
                tag({ id: 'a' }),
                tag({ id: 'b', name: 'safety' }),
            ];

            expect(store.libraryById('b')?.name).toBe('safety');
        });

        it('returns undefined for an unknown id', () => {
            const store = useTagsStore();
            store.library = [tag({ id: 'a' })];

            expect(store.libraryById('missing')).toBeUndefined();
        });
    });

    describe('setAttached + attachedTagsFor', () => {
        it('maps attached ids to library rows in order', () => {
            const store = useTagsStore();
            store.library = [
                tag({ id: 'a' }),
                tag({ id: 'b' }),
                tag({ id: 'c' }),
            ];
            store.setAttached(USER, ['c', 'a']);

            expect(store.attachedTagsFor(USER).map((t) => t.id)).toEqual([
                'c',
                'a',
            ]);
        });

        it('filters out ids that are not in the library', () => {
            const store = useTagsStore();
            store.library = [tag({ id: 'a' })];
            store.setAttached(USER, ['a', 'ghost']);

            expect(store.attachedTagsFor(USER).map((t) => t.id)).toEqual(['a']);
        });

        it('returns an empty list for a morphable with no attached tags', () => {
            const store = useTagsStore();
            store.library = [tag({ id: 'a' })];

            expect(
                store.attachedTagsFor({ type: 'training', id: 't9' }),
            ).toEqual([]);
        });
    });

    describe('libraryCount', () => {
        it('reflects the number of cached tags', () => {
            const store = useTagsStore();

            expect(store.libraryCount).toBe(0);

            store.library = [tag({ id: 'a' }), tag({ id: 'b' })];

            expect(store.libraryCount).toBe(2);
        });
    });
});
