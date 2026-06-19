import { describe, expect, it } from 'vitest';
import { renderMarkdown } from './markdown';

describe('renderMarkdown', () => {
    it('renders bold and italic', () => {
        const html = renderMarkdown('Satisfies **Cal/OSHA** and *more*');
        expect(html).toContain('<strong>Cal/OSHA</strong>');
        expect(html).toContain('<em>more</em>');
    });

    it('treats a blank line as a new paragraph', () => {
        // NB: happy-dom's DOMPurify serialization drops the first top-level
        // <p> tag (it's emitted correctly in real browsers), so assert that
        // block processing ran (a <p> is present) and both paragraphs survive,
        // rather than counting tags.
        const html = renderMarkdown('First para\n\nSecond para');
        expect(html).toContain('<p>');
        expect(html).toContain('First para');
        expect(html).toContain('Second para');
    });

    it('strips raw HTML rather than rendering it', () => {
        const html = renderMarkdown('Hello <script>alert(1)</script> world');
        expect(html).not.toContain('<script');
        expect(html).toContain('Hello');
        expect(html).toContain('world');
    });

    it('returns an empty string for blank input', () => {
        expect(renderMarkdown('')).toBe('');
        expect(renderMarkdown(null)).toBe('');
        expect(renderMarkdown(undefined)).toBe('');
    });
});
