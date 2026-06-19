import DOMPurify from 'dompurify';
import { marked } from 'marked';

/**
 * Render the Markdown subset used by certificate text (and other rich-text
 * fields) to sanitized HTML for an in-app preview.
 *
 * Mirrors the server renderer (`Str::markdown(..., html_input: 'strip')`):
 *  - blank line → new paragraph; a single newline is a soft break (space),
 *    matching CommonMark (`breaks: false`);
 *  - `**bold**` / `*italic*`, lists, etc. are supported;
 *  - any raw HTML the author types is stripped, never rendered.
 *
 * The output is sanitized to a conservative tag allowlist so the preview can
 * never execute markup, even though the source is the author's own input.
 */
const ALLOWED_TAGS = [
    'p', 'br', 'strong', 'em', 'b', 'i', 'u',
    'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 'h3', 'h4',
];

export function renderMarkdown(source: string | null | undefined): string {
    if (!source) {
        return '';
    }

    // Mirror the server: raw HTML the author types is stripped before
    // rendering (it's never a valid certificate input). Matching `<tag …>` /
    // `</tag>` only — a stray `<` in prose is left as literal text.
    const stripped = source.replace(/<\/?[a-zA-Z][^>]*>/g, '');

    const html = marked.parse(stripped, {
        async: false,
        gfm: true,
        breaks: false,
    }) as string;

    // Belt-and-suspenders: drop anything outside the allowlist and any unsafe
    // link URLs (e.g. javascript:) that survived the strip above.
    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR: [],
    });
}
