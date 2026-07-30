<?php

namespace Tests\Feature\Cards;

use App\Support\DocMerge\PdfConverter;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\ExecutableFinder;
use Tests\Support\BuildsPresentationFixtures;
use Tests\TestCase;

/**
 * Converting many card documents in as few soffice runs as possible
 * (custom-certs C4d). Real LibreOffice — the point is the batch behaviour of
 * the actual binary, which a mock could only assume.
 */
class PdfConverterBatchTest extends TestCase
{
    use BuildsPresentationFixtures;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if ((new ExecutableFinder)->find('soffice') === null) {
            $this->markTestSkipped('LibreOffice is not installed in this image.');
        }

        $this->dir = sys_get_temp_dir().'/batch_'.uniqid();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_it_converts_several_cards_and_returns_them_in_order(): void
    {
        $inputs = [];

        foreach (['alpha', 'bravo', 'charlie'] as $name) {
            $inputs[] = $this->makeRenderableOdpFixture(
                ['<draw:frame svg:x="0.2in" svg:y="0.2in" svg:width="2.5in" svg:height="0.5in">'
                    .'<draw:text-box><text:p>'.$name.'</text:p></draw:text-box></draw:frame>'],
                path: "{$this->dir}/{$name}.odp",
            );
        }

        $pdfs = app(PdfConverter::class)->toPdfBatch($inputs, $this->dir);

        $this->assertCount(3, $pdfs);
        // Order is the contract: the imposer matches card N to person N.
        $this->assertSame(
            ["{$this->dir}/alpha.pdf", "{$this->dir}/bravo.pdf", "{$this->dir}/charlie.pdf"],
            $pdfs,
        );

        foreach ($pdfs as $pdf) {
            $this->assertFileExists($pdf);
        }
    }

    public function test_the_converted_page_is_the_card_size_the_slide_declared(): void
    {
        // The whole imposition plan is computed from the template's declared
        // card size, so a conversion that silently resized would put every
        // card in the wrong place.
        $input = $this->makeRenderableOdpFixture(path: "{$this->dir}/card.odp");

        [$pdf] = app(PdfConverter::class)->toPdfBatch([$input], $this->dir);

        $reader = new Fpdi('P', 'pt');
        $reader->setSourceFile($pdf);
        $size = $reader->getTemplateSize($reader->importPage(1));

        // 3.375in x 2.125in, within LibreOffice's rounding.
        $this->assertEqualsWithDelta(243.0, $size['width'], 0.1);
        $this->assertEqualsWithDelta(153.0, $size['height'], 0.1);
    }

    public function test_a_two_slide_card_converts_to_a_two_page_pdf(): void
    {
        // How front/back reaches the imposer: page 1 the front, page 2 the back.
        $frame = fn (string $text) => '<draw:frame svg:x="0.2in" svg:y="0.2in" svg:width="2.5in" svg:height="0.5in">'
            .'<draw:text-box><text:p>'.$text.'</text:p></draw:text-box></draw:frame>';

        $input = $this->makeRenderableOdpFixture(
            [$frame('FRONT'), $frame('BACK')],
            path: "{$this->dir}/twosided.odp",
        );

        [$pdf] = app(PdfConverter::class)->toPdfBatch([$input], $this->dir);

        $reader = new Fpdi('P', 'pt');
        $this->assertSame(2, $reader->setSourceFile($pdf));
    }

    public function test_an_input_that_produced_no_pdf_fails_loudly(): void
    {
        // The check is per-file output, not the exit code: soffice can report
        // success while skipping an input, and a missing card must not become
        // a blank cell on purchased stock.
        //
        // Note the failure mode this does NOT cover: soffice cheerfully
        // converts a text file named .odp (it renders it as plain text), so
        // "content isn't really an ODP" produces a PDF rather than an error.
        // Template validity is C2's job at upload time, not this step's.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/conversion failed for gone\.odp/i');

        app(PdfConverter::class)->toPdfBatch(["{$this->dir}/gone.odp"], $this->dir);
    }
}
