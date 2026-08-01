import { describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';
import { useListDrag } from '@/composables/useListDrag';

const KEYS = ['a', 'b', 'c'];

function makeDrag(reorder = vi.fn(), keys = KEYS) {
    return {
        reorder,
        ...useListDrag(
            computed(() => keys),
            reorder,
        ),
    };
}

const fakeEvent = (): DragEvent =>
    ({
        dataTransfer: { setData: vi.fn() },
        preventDefault: vi.fn(),
    }) as unknown as DragEvent;

/** Drive a whole drag: pick up `from`, hover `to`, release. */
function drag(
    d: ReturnType<typeof makeDrag>,
    from: string,
    to: string | null,
): void {
    (d.sourceAttrs(from).onDragstart as (e: DragEvent) => void)(fakeEvent());

    if (to !== null) {
        (d.targetAttrs(to).onDragover as (e: DragEvent) => void)(fakeEvent());
    }

    (d.sourceAttrs(from).onDragend as () => void)();
}

describe('useListDrag', () => {
    it('commits the new order when the drag is released', () => {
        const d = makeDrag();

        drag(d, 'a', 'c');

        expect(d.reorder).toHaveBeenCalledWith(['b', 'c', 'a']);
    });

    it('commits on dragend rather than on drop', () => {
        /*
         * drop is unreliable: released over a child element (a button inside
         * the row, an input) it never fires, and the drag silently does
         * nothing. dragend always fires.
         */
        const d = makeDrag();
        const source = d.sourceAttrs('a');

        (source.onDragstart as (e: DragEvent) => void)(fakeEvent());
        (d.targetAttrs('c').onDragover as (e: DragEvent) => void)(fakeEvent());

        expect(d.reorder).not.toHaveBeenCalled();

        (source.onDragend as () => void)();

        expect(d.reorder).toHaveBeenCalledOnce();
    });

    it('does nothing when released without ever hovering another row', () => {
        const d = makeDrag();

        drag(d, 'a', null);

        expect(d.reorder).not.toHaveBeenCalled();
    });

    it('does nothing when a row is dropped on itself', () => {
        const d = makeDrag();

        drag(d, 'b', 'b');

        expect(d.reorder).not.toHaveBeenCalled();
    });

    it('ignores a hover when no drag is in progress', () => {
        // Rows are drop targets permanently; only a live drag may claim one.
        const d = makeDrag();
        const event = fakeEvent();

        (d.targetAttrs('c').onDragover as (e: DragEvent) => void)(event);

        expect(d.overKey.value).toBeNull();
        expect(event.preventDefault).not.toHaveBeenCalled();
    });

    it('accepts the drop by preventing default on a hovered row', () => {
        // Without preventDefault the browser refuses the drop outright.
        const d = makeDrag();
        const event = fakeEvent();

        (d.sourceAttrs('a').onDragstart as (e: DragEvent) => void)(fakeEvent());
        (d.targetAttrs('c').onDragover as (e: DragEvent) => void)(event);

        expect(event.preventDefault).toHaveBeenCalled();
        expect(d.overKey.value).toBe('c');
    });

    it('exposes which row is moving and which is the target', () => {
        const d = makeDrag();

        (d.sourceAttrs('a').onDragstart as (e: DragEvent) => void)(fakeEvent());
        (d.targetAttrs('c').onDragover as (e: DragEvent) => void)(fakeEvent());

        expect(d.dragKey.value).toBe('a');
        expect(d.overKey.value).toBe('c');
    });

    it('clears its state once the drag ends', () => {
        const d = makeDrag();

        drag(d, 'a', 'c');

        expect(d.dragKey.value).toBeNull();
        expect(d.overKey.value).toBeNull();
    });

    it('marks the source draggable and seeds the drag payload', () => {
        // Firefox will not start a drag unless dataTransfer carries something.
        const d = makeDrag();
        const event = fakeEvent();

        expect(d.sourceAttrs('a').draggable).toBe(true);

        (d.sourceAttrs('a').onDragstart as (e: DragEvent) => void)(event);

        expect(event.dataTransfer?.setData).toHaveBeenCalledWith(
            'text/plain',
            'a',
        );
    });

    it('survives a key that has since left the list', () => {
        // The list is reactive; a row can be removed mid-drag.
        const d = makeDrag(vi.fn(), ['a', 'b']);

        drag(d, 'a', 'gone');

        expect(d.reorder).not.toHaveBeenCalled();
    });
});
