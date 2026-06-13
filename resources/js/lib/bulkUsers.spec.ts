import { describe, expect, it } from 'vitest';
import {
    applyPaste,
    emptyRow,
    isRowEmpty,
    parsePastedGrid,
    validateGrid,
} from '@/lib/bulkUsers';
import type { GridRow } from '@/lib/bulkUsers';

function row(overrides: Partial<GridRow> = {}): GridRow {
    return { ...emptyRow(), ...overrides };
}

describe('parsePastedGrid', () => {
    it('splits rows on newlines and cells on tabs', () => {
        expect(parsePastedGrid('Ada\tLovelace\nGrace\tHopper')).toEqual([
            ['Ada', 'Lovelace'],
            ['Grace', 'Hopper'],
        ]);
    });

    it('ignores a trailing newline and handles CRLF', () => {
        expect(parsePastedGrid('A\tB\r\nC\tD\r\n')).toEqual([
            ['A', 'B'],
            ['C', 'D'],
        ]);
    });

    it('returns empty for blank text', () => {
        expect(parsePastedGrid('')).toEqual([]);
        expect(parsePastedGrid('\n')).toEqual([]);
    });
});

describe('applyPaste', () => {
    it('fills cells from the start position by column order and grows rows', () => {
        const rows = [emptyRow()];
        const grid = [
            ['Ada', '', 'Lovelace', 'ada@x.com'],
            ['Grace', '', 'Hopper', 'grace@x.com'],
        ];
        const out = applyPaste(rows, grid, 0, 0);

        expect(out).toHaveLength(2);
        expect(out[0]).toMatchObject({
            f_name: 'Ada',
            l_name: 'Lovelace',
            email: 'ada@x.com',
        });
        expect(out[1]).toMatchObject({
            f_name: 'Grace',
            l_name: 'Hopper',
            email: 'grace@x.com',
        });
    });

    it('respects a column offset and trims cells', () => {
        const out = applyPaste([emptyRow()], [['  Lovelace  ']], 0, 2); // startCol 2 = l_name
        expect(out[0].l_name).toBe('Lovelace');
        expect(out[0].f_name).toBe('');
    });

    it('does not mutate the input rows', () => {
        const rows = [emptyRow()];
        applyPaste(rows, [['Ada']], 0, 0);
        expect(rows[0].f_name).toBe('');
    });
});

describe('isRowEmpty', () => {
    it('treats a fresh row (only default role) as empty', () => {
        expect(isRowEmpty(emptyRow())).toBe(true);
    });
    it('is not empty once any field is set', () => {
        expect(isRowEmpty(row({ f_name: 'Ada' }))).toBe(false);
    });
});

describe('validateGrid', () => {
    it('flags missing first/last name on touched rows only', () => {
        const errors = validateGrid(
            [emptyRow(), row({ email: 'x@y.com' })],
            new Set(),
        );
        expect(errors[0]).toBeUndefined(); // blank row skipped
        expect(errors[1]).toMatchObject({
            f_name: 'First name required',
            l_name: 'Last name required',
        });
    });

    it('flags an invalid email', () => {
        const errors = validateGrid(
            [row({ f_name: 'A', l_name: 'B', email: 'nope' })],
            new Set(),
        );
        expect(errors[0].email).toBe('Invalid email');
    });

    it('flags an email already in use by an existing user', () => {
        const errors = validateGrid(
            [row({ f_name: 'A', l_name: 'B', email: 'Taken@X.com' })],
            new Set(['taken@x.com']),
        );
        expect(errors[0].email).toBe('Already in use');
    });

    it('flags the duplicate email within the batch (both rows)', () => {
        const errors = validateGrid(
            [
                row({ f_name: 'A', l_name: 'B', email: 'dup@x.com' }),
                row({ f_name: 'C', l_name: 'D', email: 'DUP@x.com' }),
            ],
            new Set(),
        );
        expect(errors[0].email).toBe('Duplicate in this batch');
        expect(errors[1].email).toBe('Duplicate in this batch');
    });

    it('passes a clean row', () => {
        const errors = validateGrid(
            [row({ f_name: 'A', l_name: 'B', email: 'fresh@x.com' })],
            new Set(),
        );
        expect(errors[0]).toBeUndefined();
    });
});
