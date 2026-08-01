<?php

namespace App\Support\Cards;

use App\Support\DocMerge\ZipXmlEditor;

/**
 * Turn the marked rich values in a merged card into real formatting
 * (custom-certs C5): PPTX runs, ODP spans.
 *
 * Runs *after* the merge, not before it. OpenTBS substitutes a value into one
 * text node, and only once that has happened is the author's own run visible —
 * which is what has to be carried onto the runs this emits. A card designer
 * sets `${endorsement}` to 12pt Arial in a particular colour; producing fresh
 * runs without cloning that would quietly reset the text to the theme default,
 * and the first anyone knows of it is a box of misprinted cards.
 *
 * Consequently markdown here is **additive**: it can switch bold on, never
 * off. A template that bolds the whole placeholder keeps its bold.
 *
 * The two formats differ in exactly one respect. PPTX carries formatting
 * inline on `<a:rPr>`, so a run can be cloned and amended. ODP can't — a
 * `<text:span>` has to point at a named style, so the styles used get minted
 * into the document's `<office:automatic-styles>` on the way past.
 */
class RichTextExpander
{
    /** Style names minted into an ODP. Prefixed so they cannot meet an author's own. */
    private const ODP_STYLE_PREFIX = 'SMCRich';

    public function __construct(private readonly ZipXmlEditor $zip = new ZipXmlEditor) {}

    /**
     * Expand every marked value in a merged document, in place.
     *
     * @param  string  $extension  pptx or odp — the two card design formats
     */
    public function expand(string $path, string $extension): void
    {
        $this->zip->each($path, function (string $name, string $content) use ($extension): ?string {
            $expanded = $this->expandXml($content, $extension);

            return $expanded === $content ? null : $expanded;
        });
    }

    /** One XML subfile. Public because it is where all the behaviour is. */
    public function expandXml(string $xml, string $extension): string
    {
        // Most cards define no formatted field; leave their XML alone rather
        // than rewriting it to produce the same bytes.
        if (! str_contains($xml, RichTextMarkup::OPEN) && ! str_contains($xml, RichTextMarkup::CLOSE)) {
            return $xml;
        }

        return $extension === 'odp'
            ? $this->expandOdp($xml)
            : $this->expandPptx($xml);
    }

    // ---- pptx ----------------------------------------------------------

    /**
     * Rebuild each `<a:r>` holding a marked value into the sequence of runs
     * the value describes. `<a:r>` cannot nest, so matching to the first
     * closing tag is safe.
     */
    private function expandPptx(string $xml): string
    {
        $out = preg_replace_callback(
            '/<a:r\b[^>]*>(.*?)<\/a:r>/su',
            fn (array $m): string => str_contains($m[1], RichTextMarkup::OPEN)
                ? $this->rebuildPptxRun($m[1])
                : $m[0],
            $xml,
        ) ?? $xml;

        // A marker outside a run has nowhere to be expanded — drop it rather
        // than let a private-use character print as a hollow box.
        return $this->stripMarkers($out);
    }

    private function rebuildPptxRun(string $inner): string
    {
        $rPr = preg_match('/^\s*(<a:rPr\b(?:[^>]*\/>|[^>]*>.*?<\/a:rPr>))/su', $inner, $m) ? $m[1] : '';
        $text = preg_match('/<a:t\b[^>]*>(.*?)<\/a:t>/su', $inner, $m) ? $m[1] : '';

        $out = '';

        foreach ($this->segments($text) as [$marked, $content]) {
            if (! $marked) {
                // Already XML-escaped: it came through the merge that way and
                // re-escaping would double it.
                $out .= $content === '' ? '' : $this->pptxRun($rPr, $content);

                continue;
            }

            foreach (RichTextMarkup::parse($content) as $i => $line) {
                if ($i > 0) {
                    $out .= '<a:br/>';
                }

                foreach ($line as $span) {
                    $out .= $this->pptxRun(
                        $this->applyPptxFlags($rPr, $span->bold, $span->italic),
                        $this->escape($span->text),
                    );
                }
            }
        }

        return $out;
    }

    private function pptxRun(string $rPr, string $escapedText): string
    {
        return '<a:r>'.$rPr.'<a:t>'.$escapedText.'</a:t></a:r>';
    }

    /**
     * Switch bold/italic on in a copy of the author's run properties, leaving
     * everything else — size, font, colour, language — exactly as it was.
     */
    private function applyPptxFlags(string $rPr, bool $bold, bool $italic): string
    {
        if (! $bold && ! $italic) {
            return $rPr;
        }

        if ($rPr === '') {
            return '<a:rPr'.($bold ? ' b="1"' : '').($italic ? ' i="1"' : '').'/>';
        }

        return preg_replace_callback(
            '/^<a:rPr\b([^>]*?)(\/?)>/s',
            function (array $m) use ($bold, $italic): string {
                $attrs = $m[1];

                if ($bold) {
                    $attrs = $this->setAttribute($attrs, 'b');
                }

                if ($italic) {
                    $attrs = $this->setAttribute($attrs, 'i');
                }

                return '<a:rPr'.$attrs.$m[2].'>';
            },
            $rPr,
            1,
        ) ?? $rPr;
    }

    /**
     * Set `$name="1"`, replacing any existing value — a template that turned
     * bold off must not win over a value that asks for it.
     *
     * The leading `\s` keeps `b` from matching inside `bwMode="auto"`.
     */
    private function setAttribute(string $attrs, string $name): string
    {
        if (preg_match('/\s'.$name.'="[^"]*"/', $attrs) === 1) {
            return preg_replace('/\s'.$name.'="[^"]*"/', ' '.$name.'="1"', $attrs, 1) ?? $attrs;
        }

        return $attrs.' '.$name.'="1"';
    }

    // ---- odp -----------------------------------------------------------

    private function expandOdp(string $xml): string
    {
        $used = [];

        $body = preg_replace_callback(
            '/'.RichTextMarkup::OPEN.'(.*?)'.RichTextMarkup::CLOSE.'/su',
            function (array $m) use (&$used): string {
                $out = '';

                foreach (RichTextMarkup::parse($this->decode($m[1])) as $i => $line) {
                    if ($i > 0) {
                        $out .= '<text:line-break/>';
                    }

                    foreach ($line as $span) {
                        $text = $this->escape($span->text);
                        $style = $this->odpStyleName($span);

                        if ($style === null) {
                            // Unformatted: a span per word would bloat every
                            // card for no visual difference.
                            $out .= $text;

                            continue;
                        }

                        $used[$style] = $span;
                        $out .= '<text:span text:style-name="'.$style.'">'.$text.'</text:span>';
                    }
                }

                return $out;
            },
            $xml,
        ) ?? $xml;

        return $this->declareOdpStyles($this->stripMarkers($body), $used);
    }

    private function odpStyleName(RichSpan $span): ?string
    {
        $suffix = ($span->bold ? 'B' : '').($span->italic ? 'I' : '');

        return $suffix === '' ? null : self::ODP_STYLE_PREFIX.$suffix;
    }

    /**
     * Mint the text styles the spans point at. ODP has no inline equivalent of
     * `<a:rPr>`: a span names a style, and an undeclared name renders as
     * nothing at all.
     *
     * The asian/complex variants matter — without them LibreOffice leaves CJK
     * and RTL text unbolded while the Latin text beside it changes.
     *
     * @param  array<string, RichSpan>  $used
     */
    private function declareOdpStyles(string $xml, array $used): string
    {
        if ($used === []) {
            return $xml;
        }

        $styles = '';

        foreach ($used as $name => $span) {
            $properties = '';

            if ($span->bold) {
                $properties .= ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"';
            }

            if ($span->italic) {
                $properties .= ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"';
            }

            $styles .= '<style:style style:name="'.$name.'" style:family="text">'
                .'<style:text-properties'.$properties.'/>'
                .'</style:style>';
        }

        if (str_contains($xml, '</office:automatic-styles>')) {
            return str_replace('</office:automatic-styles>', $styles.'</office:automatic-styles>', $xml);
        }

        /*
         * A document declaring no automatic styles writes the element
         * self-closing, so there is no closing tag to insert before. It has to
         * be opened out rather than a second block appended: ODF allows
         * exactly one, and LibreOffice ignores the extra — leaving every span
         * pointing at a style that does not exist, which renders as no
         * formatting at all rather than as an error.
         */
        if (preg_match('/<office:automatic-styles\b([^>]*)\/>/', $xml, $m) === 1) {
            return str_replace(
                $m[0],
                '<office:automatic-styles'.$m[1].'>'.$styles.'</office:automatic-styles>',
                $xml,
            );
        }

        // No block at all: the styles still have to land somewhere.
        return preg_replace(
            '/<office:body\b/',
            '<office:automatic-styles>'.$styles.'</office:automatic-styles><office:body',
            $xml,
            1,
        ) ?? $xml;
    }

    // ---- shared --------------------------------------------------------

    /**
     * Split text into [isMarked, content] pairs. Even positions are the
     * document's own (already-escaped) text, odd ones a marked value.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private function segments(string $text): array
    {
        $parts = preg_split(
            '/'.RichTextMarkup::OPEN.'(.*?)'.RichTextMarkup::CLOSE.'/su',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return [[false, $text]];
        }

        $segments = [];

        foreach ($parts as $i => $part) {
            $marked = $i % 2 === 1;

            $segments[] = [
                $marked,
                $marked ? $this->decode($part) : $this->stripMarkers($part),
            ];
        }

        return $segments;
    }

    /**
     * The value came through the merge XML-escaped, so `&amp;` has to become
     * `&` before the markdown is read — otherwise the parser sees entities
     * rather than text, and the re-escape on the way out doubles them.
     */
    private function decode(string $xml): string
    {
        return html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }

    private function stripMarkers(string $text): string
    {
        return str_replace([RichTextMarkup::OPEN, RichTextMarkup::CLOSE], '', $text);
    }
}
