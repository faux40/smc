import { describe, expect, it } from 'vitest';
import { useGeneratedDoc } from '@/composables/useGeneratedDoc';

describe('useGeneratedDoc', () => {
    it('starts closed with nothing active', () => {
        const { open, active } = useGeneratedDoc();

        expect(open.value).toBe(false);
        expect(active.value).toBeNull();
    });

    it('builds the endpoint URL for a class document and opens the viewer', () => {
        const { open, active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'summary', 'Class summary');

        expect(open.value).toBe(true);
        expect(active.value).toEqual({
            kind: 'summary',
            title: 'Class summary',
            classId: 'c1',
            src: '/api/classes/c1/summary',
            columns: undefined,
        });
    });

    it('maps sign-in to its hyphenated path', () => {
        // The kind is the UI's word; the route segment is the server's. They
        // differ for exactly one document, which is why the map exists.
        const { active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'sign-in', 'Sign-in sheet');

        expect(active.value?.src).toBe('/api/classes/c1/sign-in-sheet');
    });

    it('carries a column selection on the URL and on the doc', () => {
        // Both, deliberately: the src renders the preview and the doc drives
        // "save to this class's files". A filed copy with different columns
        // than the one on screen would be a quiet lie.
        const { active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'name-check', 'Name check sheet', [
            'full_name',
            'employee_number',
        ]);

        expect(active.value?.src).toBe(
            '/api/classes/c1/name-check?columns%5B%5D=full_name&columns%5B%5D=employee_number',
        );
        expect(active.value?.columns).toEqual([
            'full_name',
            'employee_number',
        ]);
    });

    it('encodes column keys rather than trusting them', () => {
        const { active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'name-check', 'Name check sheet', ['a b&c']);

        expect(active.value?.src).toContain('columns%5B%5D=a%20b%26c');
    });

    it('omits the query string when no columns are chosen', () => {
        const { active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'name-check', 'Name check sheet', []);

        expect(active.value?.src).toBe('/api/classes/c1/name-check');
    });

    it('re-targets at a different class without leaking the previous one', () => {
        // The classes index opens documents for whichever row was clicked, so
        // a stale classId would file the sheet against the wrong class.
        const { active, openDoc } = useGeneratedDoc();

        openDoc('c1', 'summary', 'Class summary');
        openDoc('c2', 'sign-in', 'Sign-in sheet');

        expect(active.value?.classId).toBe('c2');
        expect(active.value?.src).toBe('/api/classes/c2/sign-in-sheet');
    });
});
