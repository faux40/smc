/**
 * Moving one item within a list — the whole of "reordering", shared by the
 * drag composable and by every keyboard handler that offers the same thing
 * without a mouse.
 */

/**
 * `list` with the item at `from` lifted out and dropped at `to`, where `to`
 * is read against the list *after* the removal — the semantics a drag has,
 * where dropping onto a row means taking its place.
 *
 * Out-of-range indexes return the list unchanged rather than throwing:
 * callers derive them from drag state and key handlers, where a stale index
 * is ordinary, and `splice(-1)` would quietly mangle the far end of the list.
 *
 * @returns a new array; the original is never mutated
 */
export function moveItem<T>(list: T[], from: number, to: number): T[] {
    const inRange = (i: number): boolean => i >= 0 && i < list.length;

    if (from === to || !inRange(from) || !inRange(to)) {
        return [...list];
    }

    const next = [...list];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}
