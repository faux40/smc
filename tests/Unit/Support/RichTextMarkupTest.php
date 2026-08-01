<?php

namespace Tests\Unit\Support;

use App\Support\Cards\RichSpan;
use App\Support\Cards\RichTextMarkup;
use PHPUnit\Framework\TestCase;

/**
 * The markdown subset a `rich` card field prints (custom-certs C5): bold,
 * italic and line breaks, and nothing else.
 *
 * Parsing is deliberately separate from emitting. This half answers "what did
 * the author mean", in a form both the PPTX and ODP writers can consume, and
 * is where every awkward markdown case gets pinned — the emitters then only
 * have to turn spans into runs.
 *
 * Built on league/commonmark rather than a hand-rolled scanner, so the subset
 * agrees with the certificate text the app already renders (`Str::markdown`)
 * instead of being a second, slightly-different dialect.
 */
class RichTextMarkupTest extends TestCase
{
    /** @return array<int, array<int, string>> plain text per line, for readable assertions */
    private function text(string $markdown): array
    {
        return array_map(
            fn (array $line) => array_map(fn (RichSpan $s) => $s->text, $line),
            RichTextMarkup::parse($markdown),
        );
    }

    /** @return array<int, string> a compact "flags:text" rendering of one line */
    private function flags(string $markdown, int $line = 0): array
    {
        return array_map(
            fn (RichSpan $s) => sprintf(
                '%s%s:%s',
                $s->bold ? 'b' : '',
                $s->italic ? 'i' : '',
                $s->text,
            ),
            RichTextMarkup::parse($markdown)[$line] ?? [],
        );
    }

    public function test_plain_text_is_one_unformatted_span(): void
    {
        $this->assertSame([['Certified in First Aid']], $this->text('Certified in First Aid'));
        $this->assertSame([':Certified in First Aid'], $this->flags('Certified in First Aid'));
    }

    public function test_nothing_in_produces_nothing_out(): void
    {
        // An empty rich value is the common case — most cards define a
        // formatted field and leave it blank on most classes.
        $this->assertSame([], RichTextMarkup::parse(''));
        $this->assertSame([], RichTextMarkup::parse('   '));
        $this->assertSame([], RichTextMarkup::parse("\n\n"));
    }

    public function test_bold_in_both_spellings(): void
    {
        $this->assertSame(['b:Expires'], $this->flags('**Expires**'));
        $this->assertSame(['b:Expires'], $this->flags('__Expires__'));
    }

    public function test_italic_in_both_spellings(): void
    {
        $this->assertSame(['i:Expires'], $this->flags('*Expires*'));
        $this->assertSame(['i:Expires'], $this->flags('_Expires_'));
    }

    public function test_formatting_splits_a_line_into_spans(): void
    {
        $this->assertSame(
            [':Valid until ', 'b:June 2027', ':.'],
            $this->flags('Valid until **June 2027**.'),
        );
    }

    public function test_bold_and_italic_nest(): void
    {
        // The inner run carries both, which is the whole reason spans hold
        // flags rather than a single style name.
        $this->assertSame(
            ['b:Trainer ', 'bi:Rita'],
            $this->flags('**Trainer *Rita***'),
        );
    }

    public function test_adjacent_spans_with_the_same_formatting_are_merged(): void
    {
        // commonmark splits text nodes at entity and escape boundaries; left
        // alone that emits a separate XML run per fragment for no reason.
        $this->assertSame([':A & B'], $this->flags('A & B'));
        $this->assertSame([':100% safe'], $this->flags('100% safe'));
    }

    public function test_a_single_newline_is_a_line_break(): void
    {
        // Matches the certificate renderer's `breaks: true`: authors expect a
        // carriage return to show up as one.
        $this->assertSame([['Line one'], ['Line two']], $this->text("Line one\nLine two"));
    }

    public function test_a_blank_line_is_also_just_a_line_break(): void
    {
        // A wallet card has no paragraph spacing to give, so a paragraph
        // break degrades to the same thing a newline does.
        $this->assertSame([['First'], ['Second']], $this->text("First\n\nSecond"));
    }

    public function test_windows_line_endings_behave_the_same(): void
    {
        $this->assertSame([['One'], ['Two']], $this->text("One\r\nTwo"));
    }

    public function test_formatting_carries_across_a_line_break(): void
    {
        // Emitters work a line at a time, so a bold run spanning a break has
        // to arrive already split — both halves bold.
        $this->assertSame(
            [['b:Two'], ['b:lines']],
            [$this->flags("**Two\nlines**", 0), $this->flags("**Two\nlines**", 1)],
        );
    }

    public function test_an_unmatched_marker_stays_literal(): void
    {
        // "2 * 3" is arithmetic, not an unterminated emphasis.
        $this->assertSame([':2 * 3 = 6'], $this->flags('2 * 3 = 6'));
        $this->assertSame([':**unclosed'], $this->flags('**unclosed'));
    }

    public function test_an_escaped_marker_prints_as_itself(): void
    {
        $this->assertSame([':*not italic*'], $this->flags('\*not italic\*'));
    }

    public function test_raw_html_is_dropped_rather_than_printed(): void
    {
        // Same stance as everywhere else in the app: author HTML is never a
        // valid input, and printing the tags on card stock is the worst of
        // the available outcomes.
        // Inline tags are peeled off and the text survives; a raw HTML *block*
        // goes entirely, content included. Both match what `Str::markdown`
        // with `html_input: strip` already does for certificate text.
        $this->assertSame([':bold'], $this->flags('<b>bold</b>'));
        $this->assertSame([], RichTextMarkup::parse('<script>alert</script>'));
    }

    public function test_syntax_outside_the_subset_degrades_to_its_text(): void
    {
        /*
         * Lists, headings and links are deliberately unsupported (they're
         * paragraph-level, or meaningless on a card). Dropping their content
         * would silently lose what someone typed, so the text survives and
         * only the decoration is lost.
         */
        $this->assertSame([['First'], ['Second']], $this->text("- First\n- Second"));
        $this->assertSame([['Heading']], $this->text('# Heading'));
        $this->assertSame([['SMC']], $this->text('[SMC](https://example.com)'));
    }

    public function test_bold_inside_an_unsupported_block_still_counts(): void
    {
        $this->assertSame(['b:First'], $this->flags('- **First**'));
    }

    public function test_characters_that_are_xml_special_pass_through_untouched(): void
    {
        // parse() works in plain text; escaping belongs to whichever emitter
        // is writing XML. Encoding here would double-escape downstream.
        $this->assertSame([['Smith & Sons']], $this->text('Smith & Sons'));
        $this->assertSame([['a < b and c > d']], $this->text('a < b and c > d'));
    }

    public function test_angle_brackets_that_look_like_a_tag_are_still_dropped(): void
    {
        /*
         * "Smith & Sons <inc>" loses the "<inc>" — commonmark reads it as an
         * HTML tag. Sharp, and worth knowing about, but NOT new: the plain
         * renderer these values go through today (CardMergeData::plain) drops
         * it identically, so cards already print this way. Matching it keeps
         * one dialect instead of making rich fields behave unlike every other
         * markdown field in the app.
         */
        $this->assertSame([['Smith & Sons']], $this->text('Smith & Sons <inc>'));
    }

    public function test_the_ends_of_a_line_are_trimmed_but_not_its_middle(): void
    {
        /*
         * Card text is usually centred, where a stray leading or trailing
         * space visibly shifts the line — and it's never deliberate. The
         * space *between* spans is another matter: it's what the author
         * typed, and eating it would run the words together.
         */
        $this->assertSame([['Centred']], $this->text("  Centred  \n"));
        $this->assertSame([':Valid until ', 'b:June'], $this->flags('Valid until **June**'));
        $this->assertSame(['b:June', ':, renew then'], $this->flags('**June**, renew then'));
    }

    public function test_marking_wraps_a_value_so_the_expander_can_find_it(): void
    {
        /*
         * Private-use characters (U+E000/U+E001): no keyboard produces them,
         * both are legal XML, and neither TBS nor the zip touches them. They
         * ride through the merge inside the value and are consumed by the
         * post-merge pass before LibreOffice ever sees the file.
         */
        $this->assertSame(
            RichTextMarkup::OPEN.'**bold**'.RichTextMarkup::CLOSE,
            RichTextMarkup::mark('**bold**'),
        );
    }

    public function test_marking_nothing_produces_nothing(): void
    {
        // An empty pair of markers is an empty run to place and a stray
        // character to print if the pass is ever skipped.
        $this->assertSame('', RichTextMarkup::mark(''));
        $this->assertSame('', RichTextMarkup::mark('   '));
    }

    public function test_marking_strips_any_marker_already_in_the_value(): void
    {
        // An unbalanced marker in the input would leave the expander matching
        // across the wrong stretch of text.
        $this->assertSame(
            RichTextMarkup::OPEN.'ab'.RichTextMarkup::CLOSE,
            RichTextMarkup::mark('a'.RichTextMarkup::OPEN.'b'.RichTextMarkup::CLOSE),
        );
    }

    public function test_a_span_never_arrives_empty(): void
    {
        // An empty run is legal XML but pointless, and an empty <a:t> in a
        // PPTX confuses LibreOffice's autofit.
        foreach (RichTextMarkup::parse("**Bold**\n\n*Italic*  \ntail") as $line) {
            foreach ($line as $span) {
                $this->assertNotSame('', $span->text);
            }
        }
    }
}
