<?php

namespace Tests\Feature\Cards;

use App\Support\Cards\PdfNormalizer;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * qpdf rewrites soffice's PDF 1.6/1.7 into something FPDI's parser can follow
 * (custom-certs C4c). The failure path is asserted unconditionally; the real
 * conversion is skipped until qpdf is in the image, so this suite documents the
 * dependency instead of pretending it isn't one.
 */
class PdfNormalizerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/qpdf_'.uniqid();
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

    private function samplePdf(): string
    {
        $pdf = new Fpdi('P', 'pt', [243.0, 153.0]);
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Text(10, 20, 'card');

        $path = "{$this->dir}/in.pdf";
        $pdf->Output('F', $path);

        return $path;
    }

    public function test_a_missing_binary_fails_loudly(): void
    {
        // The job turns this into a failed run with a readable reason, rather
        // than an empty sheet PDF nobody can explain.
        config(['services.qpdf.path' => '/nonexistent/qpdf']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/normalisation failed/i');

        app(PdfNormalizer::class)->normalize($this->samplePdf(), "{$this->dir}/out.pdf");
    }

    public function test_a_rejected_input_fails_loudly(): void
    {
        if ((new ExecutableFinder)->find('qpdf') === null) {
            $this->markTestSkipped('qpdf is not installed in this image yet (C4c).');
        }

        file_put_contents("{$this->dir}/junk.pdf", 'not a pdf at all');

        $this->expectException(\RuntimeException::class);

        app(PdfNormalizer::class)->normalize("{$this->dir}/junk.pdf", "{$this->dir}/out.pdf");
    }

    public function test_it_rewrites_a_pdf_that_fpdi_can_then_read(): void
    {
        if ((new ExecutableFinder)->find('qpdf') === null) {
            $this->markTestSkipped('qpdf is not installed in this image yet (C4c).');
        }

        $out = app(PdfNormalizer::class)->normalize(
            $this->samplePdf(),
            "{$this->dir}/out.pdf",
        );

        $reader = new Fpdi('P', 'pt');
        $this->assertSame(1, $reader->setSourceFile($out));
        // Page geometry must survive untouched — normalisation is a structural
        // rewrite, not a re-render.
        $size = $reader->getTemplateSize($reader->importPage(1));
        $this->assertSame(243.0, round($size['width'], 2));
        $this->assertSame(153.0, round($size['height'], 2));
    }
}
