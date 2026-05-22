import { describe, expect, it } from 'vitest';
import { optionalNumber } from '@/lib/forms';

describe('optionalNumber', () => {
    // A `<input type="number">` bound with Vue's v-model yields a *number*
    // (Vue coerces number inputs), but an empty/cleared one yields '' — and
    // edit-prefill may seed a string. The helper must tolerate all of these
    // without throwing (the old `.trim()`-on-a-number bug).
    it('passes a real number through', () => {
        expect(optionalNumber(4)).toBe(4);
        expect(optionalNumber(2.5)).toBe(2.5);
        expect(optionalNumber(0)).toBe(0);
    });

    it('parses a numeric string', () => {
        expect(optionalNumber('4')).toBe(4);
        expect(optionalNumber('2.5')).toBe(2.5);
    });

    it('treats empty / whitespace / null / undefined as null', () => {
        expect(optionalNumber('')).toBeNull();
        expect(optionalNumber('   ')).toBeNull();
        expect(optionalNumber(null)).toBeNull();
        expect(optionalNumber(undefined)).toBeNull();
    });
});
