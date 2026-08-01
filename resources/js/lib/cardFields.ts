/*
 * Custom card fields (custom-certs C3) — the shared shapes and the pure logic
 * the editor leans on, kept out of the component so both are testable and the
 * class-side entry form can reuse the types.
 *
 * The server owns the real rules (grammar, reserved keys, per-type limits);
 * what's here is the same grammar applied early, for feedback while typing.
 */

export type CardFieldType = 'short' | 'rich';

/** A field definition as the API serves it (CardFieldPresenter). */
export interface CardFieldRow {
    id: string;
    key: string;
    /** What the author types into the slide: `${key}`. */
    placeholder: string;
    label: string;
    type: CardFieldType;
    default_value: string | null;
    max_length: number;
    seq: number;
    /**
     * How many class answers exist for this field — served by the definitions
     * endpoint so a removal can say what it would discard. Absent where
     * definitions ride along with something else (class detail).
     */
    value_count?: number;
}

/** A definition with this class topic's answer (class-detail payload). */
export interface CardFieldWithValue extends CardFieldRow {
    value: string | null;
}

/** An editable row. `id` null = not saved yet. */
export interface CardFieldDraft {
    id: string | null;
    key: string;
    label: string;
    type: CardFieldType;
    default_value: string | null;
}

/** One row of the sync payload. */
export interface CardFieldPayload {
    id: string | null;
    key: string;
    label: string;
    type: CardFieldType;
    default_value: string | null;
}

export const CARD_FIELD_KEY_RE = /^[a-z][a-z0-9_]*$/;

/**
 * A label typed as a key: lowercase, separators collapsed to underscores.
 * A leading digit is prefixed rather than dropped ("1st Aid" → f_1st_aid), so
 * the suggestion is always legal instead of almost-legal.
 */
export function slugifyCardKey(label: string): string {
    const slug = label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (slug === '') {
        return '';
    }

    return /^[a-z]/.test(slug) ? slug : `f_${slug}`;
}

export function blankCardFieldDraft(type: CardFieldType): CardFieldDraft {
    return { id: null, key: '', label: '', type, default_value: null };
}

export function draftsFromCardFields(rows: CardFieldRow[]): CardFieldDraft[] {
    return rows.map((r) => ({
        id: r.id,
        key: r.key,
        label: r.label,
        type: r.type,
        default_value: r.default_value,
    }));
}

/**
 * What the editor shows on open: exactly the fields that are defined, and
 * nothing else.
 *
 * It used to open on four blank rows, which read as "a training gets four
 * fields" — the opposite of the truth, since the list has always been
 * dynamic (the server's ceiling is 50). An empty state plus an Add button
 * says that far better than four boxes do.
 */
export function seedCardFieldDrafts(rows: CardFieldRow[]): CardFieldDraft[] {
    return draftsFromCardFields(rows);
}

/** True when a row has been left completely untouched. */
function isBlank(draft: CardFieldDraft): boolean {
    return (
        draft.key.trim() === '' &&
        draft.label.trim() === '' &&
        (draft.default_value ?? '').trim() === ''
    );
}

/**
 * Per-row key problems, indexed by row. Only the checks that don't need the
 * server: grammar, duplicates, and a labelled row with no key. Reserved-key
 * collisions come back from the sync as `fields.N.key`, so the catalogue isn't
 * duplicated here just to say it sooner.
 *
 * @returns {} when every row is fine
 */
export function cardFieldKeyErrors(
    drafts: CardFieldDraft[],
): Record<number, string> {
    const errors: Record<number, string> = {};
    const seen = new Map<string, number[]>();

    drafts.forEach((draft, i) => {
        const key = draft.key.trim();

        if (key === '') {
            // A row with content but no key can't be saved; an empty row is
            // just an unused invitation.
            if (!isBlank(draft)) {
                errors[i] = 'A merge key is needed to save this field.';
            }

            return;
        }

        if (!CARD_FIELD_KEY_RE.test(key)) {
            errors[i] =
                'Use lowercase letters, numbers and underscores, starting with a letter.';

            return;
        }

        seen.set(key, [...(seen.get(key) ?? []), i]);
    });

    for (const rows of seen.values()) {
        if (rows.length < 2) {
            continue;
        }

        // Flag every row involved: marking only the later one implies the
        // first is acceptable.
        for (const i of rows) {
            errors[i] = 'This merge key is used twice.';
        }
    }

    return errors;
}

/**
 * The sync payload: untouched rows dropped, whitespace trimmed, empty defaults
 * as null. Order is preserved — the server turns position into `seq`, and
 * anything omitted is deleted.
 */
export function cardFieldDraftPayload(
    drafts: CardFieldDraft[],
): CardFieldPayload[] {
    return drafts
        .filter((d) => d.key.trim() !== '')
        .map((d) => {
            const key = d.key.trim();
            const defaultValue = (d.default_value ?? '').trim();

            return {
                id: d.id,
                key,
                // The server requires a label; the key is a better fallback
                // than a 422 on a field the user thought was optional.
                label: d.label.trim() === '' ? key : d.label.trim(),
                type: d.type,
                default_value: defaultValue === '' ? null : defaultValue,
            };
        });
}
