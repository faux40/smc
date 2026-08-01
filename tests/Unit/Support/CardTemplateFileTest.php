<?php

namespace Tests\Unit\Support;

use App\Support\Cards\CardTemplateFile;
use App\Support\Cards\InvalidCardTemplate;
use Tests\Support\BuildsPresentationFixtures;
// Laravel's TestCase, not PHPUnit's: the supported-font list is config.
use Tests\TestCase;

/**
 * What we can learn from an uploaded card template before it is stored: how
 * many sides it has, how big the card is, which fonts it asks for, and which
 * `${keys}` it merges. Everything here is read from the archive — the user
 * types none of it.
 */
class CardTemplateFileTest extends TestCase
{
    use BuildsPresentationFixtures;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function track(string $path): string
    {
        $this->tempFiles[] = $path;

        return $path;
    }

    // ---- slide count ---------------------------------------------------

    public function test_a_one_slide_pptx_is_a_single_sided_card(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture(['<a:t>front</a:t>'])),
            'pptx',
        );

        $this->assertSame(1, $info->slideCount);
        $this->assertFalse($info->hasBack());
    }

    public function test_a_two_slide_pptx_is_front_and_back(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture(['<a:t>front</a:t>', '<a:t>back</a:t>'])),
            'pptx',
        );

        $this->assertSame(2, $info->slideCount);
        $this->assertTrue($info->hasBack());
    }

    public function test_a_three_slide_template_is_rejected(): void
    {
        // A card has at most two sides; a third slide means the user built
        // something else (a sheet, a deck) and the merge would be wrong.
        $this->expectException(InvalidCardTemplate::class);
        $this->expectExceptionMessageMatches('/two slides/i');

        CardTemplateFile::inspect(
            $this->track($this->makePptxFixture(['<a:t>1</a:t>', '<a:t>2</a:t>', '<a:t>3</a:t>'])),
            'pptx',
        );
    }

    public function test_a_slideless_template_is_rejected(): void
    {
        $this->expectException(InvalidCardTemplate::class);

        CardTemplateFile::inspect($this->track($this->makePptxFixture([])), 'pptx');
    }

    public function test_odp_pages_count_as_slides(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture(['<text:p>front</text:p>', '<text:p>back</text:p>'])),
            'odp',
        );

        $this->assertSame(2, $info->slideCount);
    }

    public function test_a_document_template_is_sent_to_the_documents_page(): void
    {
        // The mirror of the doc-template rule: the two libraries are easy to
        // confuse, so each refusal names the other rather than the format it
        // happens to want.
        $this->expectException(InvalidCardTemplate::class);
        $this->expectExceptionMessageMatches('/Documents page/i');

        CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture(['<text:p>x</text:p>'])),
            'docx',
        );
    }

    public function test_an_unrelated_file_still_gets_the_plain_rule(): void
    {
        $this->expectException(InvalidCardTemplate::class);
        $this->expectExceptionMessageMatches('/\.pptx or \.odp/i');

        CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture(['<text:p>x</text:p>'])),
            'pdf',
        );
    }

    public function test_a_notes_thumbnail_is_not_a_slide(): void
    {
        // Impress writes <draw:page-thumbnail> into each slide's notes page,
        // and a word boundary after "page" matches the hyphen — so a
        // single-sided card counted as two slides, the print run asked for
        // backs, and FPDI refused page 2 of a one-page PDF. Found in the wild.
        $info = CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture([
                '<text:p>front</text:p>'
                    .'<presentation:notes draw:style-name="dp2">'
                    .'<draw:page-thumbnail presentation:class="page"/>'
                    .'</presentation:notes>',
            ])),
            'odp',
        );

        $this->assertSame(1, $info->slideCount);
        $this->assertFalse($info->hasBack());
    }

    // ---- card size -----------------------------------------------------

    public function test_card_size_comes_from_the_pptx_slide_dimensions(): void
    {
        // 3.375 x 2.125in in EMU -> points, so the user never types the card
        // size twice.
        $info = CardTemplateFile::inspect($this->track($this->makePptxFixture()), 'pptx');

        $this->assertSame(243.0, $info->cardWidth);
        $this->assertSame(153.0, $info->cardHeight);
    }

    public function test_card_size_comes_from_the_odp_page_layout(): void
    {
        $info = CardTemplateFile::inspect($this->track($this->makeOdpFixture()), 'odp');

        $this->assertSame(243.0, $info->cardWidth);
        $this->assertSame(153.0, $info->cardHeight);
    }

    public function test_odp_page_sizes_can_be_metric(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture(
                pageWidth: '8.56cm',
                pageHeight: '54mm',
            )),
            'odp',
        );

        // 8.56cm = 242.6pt, 54mm = 153.07pt — the ISO ID-1 card.
        $this->assertEqualsWithDelta(242.6, $info->cardWidth, 0.1);
        $this->assertEqualsWithDelta(153.07, $info->cardHeight, 0.1);
    }

    // ---- merge keys ----------------------------------------------------

    public function test_merge_keys_are_extracted_from_every_slide(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture([
                '<a:t>${user_name}</a:t><a:t>${cert_id}</a:t>',
                '<a:t>${trainer_id}</a:t>',
            ])),
            'pptx',
        );

        sort($info->placeholders);
        $this->assertSame(['cert_id', 'trainer_id', 'user_name'], $info->placeholders);
    }

    // ---- fonts ---------------------------------------------------------

    public function test_pptx_typefaces_are_collected(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture([
                '<a:rPr><a:latin typeface="Arial"/></a:rPr><a:t>${user_name}</a:t>'
                .'<a:rPr><a:latin typeface="Brush Script MT"/></a:rPr>',
            ])),
            'pptx',
        );

        sort($info->fonts);
        $this->assertSame(['Arial', 'Brush Script MT'], $info->fonts);
    }

    public function test_theme_font_references_are_not_reported_as_fonts(): void
    {
        // "+mj-lt" / "+mn-lt" point at the theme, not a real family — the
        // resolved face is whatever the theme names.
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture([
                '<a:rPr><a:latin typeface="+mn-lt"/></a:rPr><a:t>x</a:t>',
            ])),
            'pptx',
        );

        $this->assertSame([], $info->fonts);
    }

    public function test_odp_font_faces_are_collected(): void
    {
        $info = CardTemplateFile::inspect(
            $this->track($this->makeOdpFixture(
                fontFaces: '<style:font-face style:name="Liberation Sans" svg:font-family="Liberation Sans"/>'
                    .'<style:font-face style:name="Zapfino" svg:font-family="Zapfino"/>',
            )),
            'odp',
        );

        sort($info->fonts);
        $this->assertSame(['Liberation Sans', 'Zapfino'], $info->fonts);
    }

    public function test_it_reports_every_declared_family_without_judging_them(): void
    {
        /*
         * Inspection answers "what does the file ask for"; whether each
         * family will actually print is SupportedFonts' question, because
         * the answer depends on the org's uploaded library and changes after
         * the upload. Two owners for that one question is how a design ends
         * up warned about a font it will happily print.
         */
        $info = CardTemplateFile::inspect(
            $this->track($this->makePptxFixture([
                '<a:rPr><a:latin typeface="Arial"/></a:rPr>'
                .'<a:rPr><a:latin typeface="Brush Script MT"/></a:rPr>',
            ])),
            'pptx',
        );

        sort($info->fonts);
        $this->assertSame(['Arial', 'Brush Script MT'], $info->fonts);
    }

    // ---- structural validation ------------------------------------------

    public function test_a_non_archive_is_rejected(): void
    {
        $path = $this->track(tempnam(sys_get_temp_dir(), 'card').'.pptx');
        file_put_contents($path, 'this is not a zip');

        $this->expectException(InvalidCardTemplate::class);

        CardTemplateFile::inspect($path, 'pptx');
    }

    public function test_an_odt_renamed_to_pptx_is_rejected(): void
    {
        // Structural validation, not mime guessing: no ppt/presentation.xml.
        $this->expectException(InvalidCardTemplate::class);

        CardTemplateFile::inspect($this->track($this->makeOdpFixture()), 'pptx');
    }

    public function test_a_text_document_renamed_to_odp_is_rejected(): void
    {
        // An ODT has content.xml too — the mimetype entry is what separates
        // a presentation from a text document.
        $path = $this->track(tempnam(sys_get_temp_dir(), 'card').'.odp');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
        $zip->addFromString('content.xml', '<office:document-content xmlns:office="urn:x"/>');
        $zip->close();

        $this->expectException(InvalidCardTemplate::class);
        $this->expectExceptionMessageMatches('/presentation/i');

        CardTemplateFile::inspect($path, 'odp');
    }
}
