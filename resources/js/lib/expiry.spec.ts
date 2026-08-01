import { describe, expect, it } from 'vitest';
import { derivedExpiry } from '@/lib/expiry';

describe('derivedExpiry', () => {
    it('adds the repeat interval to the completion date', () => {
        expect(derivedExpiry('2026-06-01', true, 365)).toBe('2027-06-01');
    });

    it('crosses month, year and leap-year boundaries', () => {
        expect(derivedExpiry('2028-02-28', true, 1)).toBe('2028-02-29');
        expect(derivedExpiry('2026-12-31', true, 1)).toBe('2027-01-01');
        expect(derivedExpiry('2026-06-01', true, 1095)).toBe('2029-05-31');
    });

    it.each([
        ['initial-only or as-needed', false, 365],
        ['repeating with no interval set', true, null],
        ['repeating with a zero interval', true, 0],
    ])('has no expiry for a training that is %s', (_n, repeating, days) => {
        // "Current" is the correct, permanent status for these — mirrors
        // ExpiryCalculator::fromRepeatDays, which is what close-out applies.
        expect(derivedExpiry('2026-06-01', repeating, days)).toBeNull();
    });

    it('has no expiry without a completion date to count from', () => {
        // The date field starts empty on a class that hasn't been closed out.
        expect(derivedExpiry('', true, 365)).toBeNull();
    });

    it('refuses a date that is not a plain calendar date', () => {
        // Anything else means a caller passed a timestamp or a display
        // string; silently returning a wrong expiry would print on a card.
        expect(derivedExpiry('2026-06-01T00:00:00Z', true, 365)).toBeNull();
        expect(derivedExpiry('06/01/2026', true, 365)).toBeNull();
    });
});
