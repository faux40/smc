import { describe, expect, it } from 'vitest';
import { addDaysToDateOnly } from '@/lib/dateOnly';

describe('addDaysToDateOnly', () => {
    it('adds days within the same month', () => {
        expect(addDaysToDateOnly('2026-06-01', 10)).toBe('2026-06-11');
    });

    it('crosses a month boundary', () => {
        expect(addDaysToDateOnly('2026-06-25', 10)).toBe('2026-07-05');
    });

    it('crosses a year boundary', () => {
        expect(addDaysToDateOnly('2026-12-31', 1)).toBe('2027-01-01');
    });

    it('crosses a leap day correctly (2024 is a leap year)', () => {
        expect(addDaysToDateOnly('2024-02-28', 1)).toBe('2024-02-29');
        expect(addDaysToDateOnly('2024-01-01', 365)).toBe('2024-12-31');
    });

    it('does not add a leap day in a non-leap year', () => {
        expect(addDaysToDateOnly('2023-02-28', 1)).toBe('2023-03-01');
    });

    it('matches the one-year-repeat case used by the completion form', () => {
        expect(addDaysToDateOnly('2026-06-01', 365)).toBe('2027-06-01');
    });

    it('throws on a malformed date', () => {
        expect(() => addDaysToDateOnly('06/01/2026', 1)).toThrow();
    });
});
