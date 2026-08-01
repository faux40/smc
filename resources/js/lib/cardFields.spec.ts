import { describe, expect, it } from 'vitest';
import {
    blankCardFieldDraft,
    cardFieldDraftPayload,
    cardFieldKeyErrors,
    draftsFromCardFields,
    seedCardFieldDrafts,
    slugifyCardKey,
} from '@/lib/cardFields';
import type { CardFieldDraft, CardFieldRow } from '@/lib/cardFields';

function row(overrides: Partial<CardFieldRow> & { id: string }): CardFieldRow {
    return {
        key: 'trainer_id',
        placeholder: '${trainer_id}',
        label: 'Trainer ID',
        type: 'short',
        default_value: null,
        max_length: 100,
        seq: 0,
        ...overrides,
    };
}

function draft(overrides: Partial<CardFieldDraft> = {}): CardFieldDraft {
    return { ...blankCardFieldDraft('short'), ...overrides };
}

describe('slugifyCardKey', () => {
    it('turns a label into a legal key', () => {
        expect(slugifyCardKey('Trainer ID')).toBe('trainer_id');
        expect(slugifyCardKey('Instructor  Card #')).toBe('instructor_card');
        expect(slugifyCardKey('OSHA-10 Endorsement')).toBe(
            'osha_10_endorsement',
        );
    });

    it('keeps a leading letter, since a key may not start with a digit', () => {
        // "1st Aid" would otherwise produce an illegal key the server rejects.
        expect(slugifyCardKey('1st Aid Level')).toBe('f_1st_aid_level');
        expect(slugifyCardKey('___')).toBe('');
    });

    it('collapses and trims separators rather than leaving doubles', () => {
        expect(slugifyCardKey('  Trainer / ID  ')).toBe('trainer_id');
    });
});

describe('seedCardFieldDrafts', () => {
    it('opens empty when nothing is defined', () => {
        /*
         * Four blank rows used to greet everyone, which read as "you get four
         * fields" — the opposite of the truth, since the list has always been
         * dynamic (server cap 50). Showing only what someone actually added
         * makes the Add button the thing that answers "how many can I have".
         */
        expect(seedCardFieldDrafts([])).toEqual([]);
    });

    it('shows exactly what is defined once fields exist', () => {
        const drafts = seedCardFieldDrafts([row({ id: 'f1' })]);

        expect(drafts).toHaveLength(1);
        expect(drafts[0].id).toBe('f1');
    });
});

describe('draftsFromCardFields', () => {
    it('carries the server row into an editable draft', () => {
        const drafts = draftsFromCardFields([
            row({ id: 'f1', key: 'trainer_id', default_value: 'INST-1' }),
            row({ id: 'f2', key: 'notes', type: 'rich', max_length: 2000 }),
        ]);

        expect(drafts[0]).toEqual({
            id: 'f1',
            key: 'trainer_id',
            label: 'Trainer ID',
            type: 'short',
            default_value: 'INST-1',
        });
        expect(drafts[1].type).toBe('rich');
    });
});

describe('cardFieldKeyErrors', () => {
    it('passes a clean set', () => {
        expect(
            cardFieldKeyErrors([
                draft({ key: 'trainer_id', label: 'Trainer ID' }),
                draft({ key: 'notes', label: 'Notes' }),
            ]),
        ).toEqual({});
    });

    it('flags a key that breaks the grammar', () => {
        const errors = cardFieldKeyErrors([
            draft({ key: 'Trainer ID', label: 'Trainer ID' }),
        ]);

        expect(errors[0]).toContain('lowercase');
    });

    it('flags both sides of a duplicate', () => {
        // Both rows are wrong from the user's point of view — highlighting only
        // the second reads as "the first one is fine".
        const errors = cardFieldKeyErrors([
            draft({ key: 'trainer_id', label: 'A' }),
            draft({ key: 'trainer_id', label: 'B' }),
        ]);

        expect(errors[0]).toContain('used twice');
        expect(errors[1]).toContain('used twice');
    });

    it('requires a key on a row that has a label', () => {
        const errors = cardFieldKeyErrors([
            draft({ key: '', label: 'Trainer' }),
        ]);

        expect(errors[0]).toContain('needed');
    });

    it('ignores a row that is entirely blank', () => {
        // The editor opens with empty rows; they are not errors, they're
        // invitations. cardFieldDraftPayload drops them.
        expect(cardFieldKeyErrors([draft(), draft()])).toEqual({});
    });
});

describe('cardFieldDraftPayload', () => {
    it('drops untouched rows and trims what is left', () => {
        const payload = cardFieldDraftPayload([
            draft({ key: ' trainer_id ', label: '  Trainer ID  ' }),
            draft(),
        ]);

        expect(payload).toEqual([
            {
                id: null,
                key: 'trainer_id',
                label: 'Trainer ID',
                type: 'short',
                default_value: null,
            },
        ]);
    });

    it('sends an empty default as null rather than an empty string', () => {
        const payload = cardFieldDraftPayload([
            draft({
                key: 'trainer_id',
                label: 'Trainer ID',
                default_value: '',
            }),
        ]);

        expect(payload[0].default_value).toBeNull();
    });

    it('labels a keyed row that has no label with its key', () => {
        // The server requires a label; falling back to the key beats a 422 on
        // something the user reasonably considers optional.
        const payload = cardFieldDraftPayload([
            draft({ key: 'trainer_id', label: '' }),
        ]);

        expect(payload[0].label).toBe('trainer_id');
    });

    it('keeps an existing row that has been blanked out of the payload', () => {
        // Clearing a saved row's key is how you delete it: the sync endpoint
        // removes what the payload omits.
        const payload = cardFieldDraftPayload([
            draft({ id: 'f1', key: '', label: '' }),
        ]);

        expect(payload).toEqual([]);
    });

    it('preserves order, which is what the server turns into seq', () => {
        const payload = cardFieldDraftPayload([
            draft({ key: 'second', label: 'Second' }),
            draft({ key: 'first', label: 'First' }),
        ]);

        expect(payload.map((f) => f.key)).toEqual(['second', 'first']);
    });
});
