<?php

namespace App\Support\Cards;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * The markdown a `rich` card field prints, read into spans (custom-certs C5).
 *
 * The supported subset is **bold**, *italic* and line breaks — all three are
 * run-level, so each is a straight run swap in both PPTX and ODP. Bullets,
 * headings and the rest are paragraph-level: supporting them would mean
 * splitting and rebuilding paragraphs, for very little on a wallet card. They
 * are not rejected, though — their text survives and only the decoration is
 * lost, because silently dropping what someone typed onto purchased stock is
 * the worse failure.
 *
 * Parsing is league/commonmark rather than a hand-rolled scanner so this
 * agrees with the certificate text the app already renders through
 * `Str::markdown`, instead of being a second, subtly different dialect. It
 * also means escapes, nesting and unmatched markers are already correct.
 *
 * Output is plain text: XML escaping belongs to whichever emitter is writing
 * the document, and doing it here would double-escape downstream.
 */
class RichTextMarkup
{
    /**
     * The markers a rich value wears through the merge.
     *
     * Private-use characters: no keyboard produces them, both are legal XML
     * (#xE000–#xFFFD), and neither TBS nor the zip gives them any meaning. A
     * printable token would risk colliding with something an author typed.
     *
     * They exist only between {@see mark()} and {@see RichTextExpander}, which
     * consumes them before LibreOffice ever opens the file.
     */
    public const OPEN = "\u{E000}";

    public const CLOSE = "\u{E001}";

    /**
     * Wrap a value so the post-merge pass can find it in the document.
     *
     * Blank in, blank out: an empty pair of markers is an empty run to place,
     * and a stray character to print if anything downstream skips the pass.
     */
    public static function mark(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        // Nobody types a private-use character, but a paste from somewhere odd
        // could carry one — and an unbalanced marker would leave the expander
        // matching across the wrong stretch of text.
        $clean = str_replace([self::OPEN, self::CLOSE], '', $markdown);

        return self::OPEN.$clean.self::CLOSE;
    }

    /**
     * Lines of spans. A line break — typed, or implied by a paragraph or list
     * item ending — starts a new line, so emitters can place their own break
     * element between them without re-reading the markdown.
     *
     * @return list<list<RichSpan>>
     */
    public static function parse(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        $environment = new Environment([
            // Author HTML is never a valid card value. Inline tags are peeled
            // off and their text kept; a raw HTML block goes entirely.
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        $document = (new MarkdownParser($environment))->parse(
            // CommonMark handles CRLF, but normalising first keeps the
            // literals we read out of Text nodes free of stray \r.
            str_replace(["\r\n", "\r"], "\n", $markdown),
        );

        $lines = [];
        $current = [];

        self::walk($document, false, false, $lines, $current);
        self::endLine($lines, $current);

        return $lines;
    }

    /**
     * @param  list<list<RichSpan>>  $lines
     * @param  list<RichSpan>  $current
     */
    private static function walk(
        Node $node,
        bool $bold,
        bool $italic,
        array &$lines,
        array &$current,
    ): void {
        foreach ($node->children() as $child) {
            /*
             * Raw HTML the author typed. `html_input: strip` is a *renderer*
             * setting — the parser still builds these nodes, so they have to
             * be dropped here or the tags print literally on the card.
             *
             * HtmlInline is one tag: skipping it peels `<b>bold</b>` down to
             * "bold". HtmlBlock is the whole block, content included, which
             * is what `Str::markdown` with strip already does to it.
             */
            if ($child instanceof HtmlInline || $child instanceof HtmlBlock) {
                continue;
            }

            if ($child instanceof Newline) {
                self::endLine($lines, $current);

                continue;
            }

            if ($child instanceof Strong) {
                self::walk($child, true, $italic, $lines, $current);

                continue;
            }

            if ($child instanceof Emphasis) {
                self::walk($child, $bold, true, $lines, $current);

                continue;
            }

            // Text and Code both hold their content as a literal.
            if ($child instanceof AbstractStringContainer) {
                self::add($current, $child->getLiteral(), $bold, $italic);

                continue;
            }

            self::walk($child, $bold, $italic, $lines, $current);

            // A paragraph, heading or list item ending reads as a line break:
            // a card has no paragraph spacing to give it instead.
            if ($child instanceof AbstractBlock) {
                self::endLine($lines, $current);
            }
        }
    }

    /**
     * Append text, extending the previous span when the formatting matches.
     *
     * commonmark splits Text nodes at escape and entity boundaries, so
     * "A & B" arrives in three pieces; emitted as-is that would be three
     * identical XML runs for one phrase.
     *
     * @param  list<RichSpan>  $current
     */
    private static function add(array &$current, string $text, bool $bold, bool $italic): void
    {
        if ($text === '') {
            return;
        }

        $span = new RichSpan($text, $bold, $italic);
        $last = end($current);

        if ($last !== false && $last->sameFormatting($span)) {
            $current[array_key_last($current)] = $last->append($text);

            return;
        }

        $current[] = $span;
    }

    /**
     * Bank the line in progress. Blank lines are dropped rather than kept as
     * empty ones: a paragraph break and a newline mean the same thing here,
     * and doubling the gap is not what the author asked for.
     *
     * The ends are trimmed, matching the plain renderer these values go
     * through today. Card text is usually centred, where a trailing space
     * left behind by a stripped tag visibly shifts the line. Only the outer
     * edges: the space in "Valid until **June**" is between two spans and is
     * exactly as the author typed it.
     *
     * @param  list<list<RichSpan>>  $lines
     * @param  list<RichSpan>  $current
     */
    private static function endLine(array &$lines, array &$current): void
    {
        $line = self::trimEnds($current);

        if ($line !== []) {
            $lines[] = $line;
        }

        $current = [];
    }

    /**
     * @param  list<RichSpan>  $line
     * @return list<RichSpan>
     */
    private static function trimEnds(array $line): array
    {
        if ($line === []) {
            return [];
        }

        $first = array_key_first($line);
        $last = array_key_last($line);

        $line[$first] = new RichSpan(
            ltrim($line[$first]->text),
            $line[$first]->bold,
            $line[$first]->italic,
        );
        $line[$last] = new RichSpan(
            rtrim($line[$last]->text),
            $line[$last]->bold,
            $line[$last]->italic,
        );

        // A line of nothing but whitespace collapses to nothing at all.
        return array_values(array_filter($line, fn (RichSpan $s) => $s->text !== ''));
    }
}
