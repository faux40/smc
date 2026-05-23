import { beforeEach, describe, expect, it } from 'vitest';
import { updateTheme } from '@/composables/useAppearance';

/**
 * Dark mode is disabled app-wide — the app is always light. `updateTheme`
 * must never add the `dark` class (and should strip a stale one), regardless
 * of the requested value or the OS preference.
 */
describe('updateTheme (dark mode disabled)', () => {
    beforeEach(() => document.documentElement.classList.remove('dark'));

    it('never applies the dark class for any value', () => {
        updateTheme('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(false);

        updateTheme('system');
        expect(document.documentElement.classList.contains('dark')).toBe(false);

        updateTheme('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('strips a pre-existing dark class', () => {
        document.documentElement.classList.add('dark');
        updateTheme('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });
});
