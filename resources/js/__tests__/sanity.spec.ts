import { cn } from '@/lib/utils';

/*
 * Harness sanity check: proves the Vitest runner, the happy-dom DOM
 * environment, and the `@` → resources/js alias all work. If this file
 * fails, the test infra is misconfigured — not the app.
 */
describe('vitest harness sanity', () => {
    it('runs specs and evaluates assertions', () => {
        expect(1 + 1).toBe(2);
    });

    it('provides a happy-dom document', () => {
        const el = document.createElement('div');
        el.textContent = 'ok';

        expect(el.textContent).toBe('ok');
    });

    it('resolves the @ alias against a real source module', () => {
        // cn = twMerge(clsx(...)); proves the import resolved and ran.
        expect(cn('px-2', 'px-4')).toBe('px-4');
    });
});
