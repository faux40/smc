<?php

namespace Tests\Unit\Support;

use App\Support\Cards\RichTextExpander;
use App\Support\Cards\RichTextMarkup;
use PHPUnit\Framework\TestCase;

/**
 * Turning a marked rich value into real formatting (custom-certs C5), which
 * happens *after* the merge — see {@see RichTextMarkup::mark()} for why the
 * markdown travels through OpenTBS intact instead of being converted up front.
 *
 * The load-bearing requirement is that the author's own formatting survives.
 * A card designer sets `${endorsement}` to 12pt Arial in a particular colour;
 * emitting fresh runs without carrying that over would silently reset the text
 * to the theme default, and nobody finds out until it's on purchased stock.
 */
class RichTextExpanderTest extends TestCase
{
    private RichTextExpander $expander;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expander = new RichTextExpander;
    }

    /** A PPTX paragraph holding one run, whose text is $text. */
    private function pptxRun(string $text, string $rPr = '<a:rPr lang="en-US" sz="1200" dirty="0"/>'): string
    {
        return '<a:p><a:r>'.$rPr.'<a:t>'.$text.'</a:t></a:r></a:p>';
    }

    private function pptx(string $xml): string
    {
        return $this->expander->expandXml($xml, 'pptx');
    }

    /** A minimal content.xml: ODP needs somewhere to put the styles it mints. */
    private function odpDoc(string $paragraph): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content>'
            .'<office:automatic-styles><style:style style:name="P1" style:family="paragraph"/></office:automatic-styles>'
            .'<office:body><office:presentation>'.$paragraph.'</office:presentation></office:body>'
            .'</office:document-content>';
    }

    private function odp(string $xml): string
    {
        return $this->expander->expandXml($xml, 'odp');
    }

    // ---- pptx ----------------------------------------------------------

    public function test_bold_becomes_its_own_run(): void
    {
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('**Bold**')));

        $this->assertStringContainsString('<a:t>Bold</a:t>', $out);
        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\sb="1"/', $out);
    }

    public function test_italic_becomes_its_own_run(): void
    {
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('*Slanted*')));

        $this->assertStringContainsString('<a:t>Slanted</a:t>', $out);
        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\si="1"/', $out);
    }

    public function test_the_authors_run_properties_survive_on_every_run(): void
    {
        // The whole reason this is a post-merge pass over the document rather
        // than a conversion before it: only here is the author's rPr visible.
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('plain **bold**')));

        $this->assertSame(2, substr_count($out, 'sz="1200"'));
        $this->assertSame(2, substr_count($out, 'lang="en-US"'));
    }

    public function test_run_properties_with_children_survive(): void
    {
        // e.g. a colour: <a:rPr><a:solidFill>…</a:solidFill></a:rPr>.
        $rPr = '<a:rPr lang="en-US"><a:solidFill><a:srgbClr val="FF0000"/></a:solidFill></a:rPr>';

        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('**Red**'), $rPr));

        $this->assertStringContainsString('<a:srgbClr val="FF0000"/>', $out);
        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\sb="1"[^>]*>/', $out);
    }

    public function test_text_around_the_value_keeps_its_own_run(): void
    {
        // A designer may type "Note: ${endorsement}" in one text box.
        $out = $this->pptx($this->pptxRun('Note: '.RichTextMarkup::mark('**Yes**').' (end)'));

        $this->assertStringContainsString('<a:t>Note: </a:t>', $out);
        $this->assertStringContainsString('<a:t>Yes</a:t>', $out);
        $this->assertStringContainsString('<a:t> (end)</a:t>', $out);
    }

    public function test_a_line_break_becomes_a_break_element(): void
    {
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark("One\nTwo")));

        $this->assertStringContainsString('<a:br/>', $out);
        $this->assertStringContainsString('<a:t>One</a:t>', $out);
        $this->assertStringContainsString('<a:t>Two</a:t>', $out);
    }

    public function test_formatting_the_author_applied_is_never_taken_away(): void
    {
        /*
         * The template bolds the whole placeholder; the value says nothing
         * about bold. It stays bold. Markdown is additive here, not
         * authoritative — the alternative is a pass that silently un-bolds a
         * design someone deliberately set.
         */
        $out = $this->pptx($this->pptxRun(
            RichTextMarkup::mark('quiet'),
            '<a:rPr lang="en-US" b="1"/>',
        ));

        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\sb="1"/', $out);
    }

    public function test_bold_is_turned_on_even_when_the_template_turned_it_off(): void
    {
        $out = $this->pptx($this->pptxRun(
            RichTextMarkup::mark('**loud**'),
            '<a:rPr lang="en-US" b="0"/>',
        ));

        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\sb="1"/', $out);
        $this->assertStringNotContainsString('b="0"', $out);
    }

    public function test_a_run_with_no_properties_at_all_still_works(): void
    {
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('**Bold**'), ''));

        $this->assertMatchesRegularExpression('/<a:rPr[^>]*\sb="1"/', $out);
        $this->assertStringContainsString('<a:t>Bold</a:t>', $out);
    }

    public function test_xml_entities_survive_the_round_trip(): void
    {
        /*
         * The value arrives already XML-escaped from the merge, so the
         * markdown has to be decoded before parsing and re-escaped on the way
         * out. Get this wrong and an ampersand in someone's employer name
         * produces invalid XML — LibreOffice then drops the card with no
         * useful error.
         */
        $out = $this->pptx($this->pptxRun(RichTextMarkup::mark('Smith &amp; Sons **Ltd**')));

        $this->assertStringContainsString('Smith &amp; Sons', $out);
        $this->assertStringNotContainsString('Smith & Sons', $out);
        $this->assertStringContainsString('<a:t>Ltd</a:t>', $out);
    }

    public function test_a_document_with_no_marked_value_is_returned_untouched(): void
    {
        // Most cards have no formatted field; the pass must not rewrite their
        // XML for nothing.
        $xml = $this->pptxRun('Just text');

        $this->assertSame($xml, $this->pptx($xml));
    }

    public function test_several_marked_values_all_expand(): void
    {
        $xml = $this->pptxRun(RichTextMarkup::mark('**One**'))
            .$this->pptxRun(RichTextMarkup::mark('*Two*'));

        $out = $this->pptx($xml);

        $this->assertStringContainsString('<a:t>One</a:t>', $out);
        $this->assertStringContainsString('<a:t>Two</a:t>', $out);
    }

    public function test_a_stray_marker_is_never_printed(): void
    {
        /*
         * Belt and braces. A marker should always come in a pair, but if one
         * ever arrived alone the character would print as a hollow box on
         * purchased card stock. Strip it and keep the text.
         */
        $out = $this->pptx($this->pptxRun('half '.RichTextMarkup::OPEN.'open'));

        $this->assertStringNotContainsString(RichTextMarkup::OPEN, $out);
        $this->assertStringNotContainsString(RichTextMarkup::CLOSE, $out);
        $this->assertStringContainsString('half open', $out);
    }

    // ---- odp -----------------------------------------------------------

    public function test_odp_bold_becomes_a_span_with_a_minted_style(): void
    {
        $out = $this->odp($this->odpDoc(
            '<text:p text:style-name="P1">'.RichTextMarkup::mark('**Bold**').'</text:p>',
        ));

        $this->assertMatchesRegularExpression(
            '/<text:span text:style-name="[^"]+">Bold<\/text:span>/',
            $out,
        );
    }

    public function test_odp_declares_the_styles_it_uses(): void
    {
        // Unlike PPTX, ODP can't put bold on the span inline — it has to point
        // at a style, and that style has to exist in the document.
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark('**Bold** and *italic*').'</text:p>',
        ));

        $this->assertStringContainsString('fo:font-weight="bold"', $out);
        $this->assertStringContainsString('fo:font-style="italic"', $out);
        $this->assertStringContainsString('style:family="text"', $out);
        // Inside the existing automatic-styles block, not loose in the body.
        $this->assertStringContainsString('</office:automatic-styles>', $out);
        $this->assertLessThan(
            strpos($out, '<office:body>'),
            strpos($out, 'fo:font-weight="bold"'),
        );
    }

    public function test_odp_declares_only_the_styles_it_actually_used(): void
    {
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark('**Bold only**').'</text:p>',
        ));

        $this->assertStringContainsString('fo:font-weight="bold"', $out);
        $this->assertStringNotContainsString('fo:font-style="italic"', $out);
    }

    public function test_odp_keeps_the_existing_automatic_styles(): void
    {
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark('**Bold**').'</text:p>',
        ));

        $this->assertStringContainsString('style:name="P1"', $out);
    }

    public function test_odp_fills_an_empty_self_closing_styles_block(): void
    {
        /*
         * A document that declares no automatic styles writes the element
         * self-closing, so there is no `</office:automatic-styles>` to insert
         * before. Appending a second block instead would be invalid ODF —
         * the schema allows exactly one — and LibreOffice ignores the extra,
         * leaving every span pointing at a style that doesn't exist.
         */
        $out = $this->odp(
            '<office:document-content><office:automatic-styles/>'
            .'<office:body><text:p>'.RichTextMarkup::mark('**Bold**').'</text:p></office:body>'
            .'</office:document-content>',
        );

        $this->assertSame(1, substr_count($out, '<office:automatic-styles'));
        $this->assertStringContainsString('fo:font-weight="bold"', $out);
        $this->assertLessThan(
            strpos($out, '<office:body>'),
            strpos($out, 'fo:font-weight="bold"'),
        );
    }

    public function test_odp_creates_a_styles_block_when_there_is_none(): void
    {
        $out = $this->odp(
            '<office:document-content>'
            .'<office:body><text:p>'.RichTextMarkup::mark('**Bold**').'</text:p></office:body>'
            .'</office:document-content>',
        );

        $this->assertSame(1, substr_count($out, '<office:automatic-styles'));
        $this->assertStringContainsString('fo:font-weight="bold"', $out);
    }

    public function test_odp_unformatted_text_needs_no_span(): void
    {
        // A span per word would bloat every card for no visual difference.
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark('just plain').'</text:p>',
        ));

        $this->assertStringContainsString('<text:p>just plain</text:p>', $out);
    }

    public function test_odp_line_break(): void
    {
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark("One\nTwo").'</text:p>',
        ));

        $this->assertStringContainsString('<text:line-break/>', $out);
    }

    public function test_odp_entities_survive_the_round_trip(): void
    {
        $out = $this->odp($this->odpDoc(
            '<text:p>'.RichTextMarkup::mark('Smith &amp; Sons').'</text:p>',
        ));

        $this->assertStringContainsString('Smith &amp; Sons', $out);
        $this->assertStringNotContainsString('Smith & Sons', $out);
    }

    public function test_odp_with_no_marked_value_is_returned_untouched(): void
    {
        $xml = $this->odpDoc('<text:p>Just text</text:p>');

        $this->assertSame($xml, $this->odp($xml));
    }
}
