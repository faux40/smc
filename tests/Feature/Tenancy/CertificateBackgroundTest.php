<?php

namespace Tests\Feature\Tenancy;

use App\Support\CertificateRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class CertificateBackgroundTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal cert view-model row. */
    private function certs(int $n = 1): array
    {
        return array_map(fn (int $i) => [
            'org_name' => 'Acme', 'student_name' => "Person $i",
            'cert_title' => 'Title', 'cert_html' => '<p>Body</p>',
            'cert_id' => "C-$i", 'issue_date' => 'June 1, 2026',
            'expires' => '—', 'hours' => '4.00', 'trainer' => 'Jane Doe',
            'show_signature' => true,
        ], range(1, $n));
    }

    /** Write a throwaway single-page landscape-Letter PDF to act as a background. */
    private function makeBackgroundPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'certbg').'.pdf';
        file_put_contents(
            $path,
            Pdf::loadHTML('<div style="background:#eef">bg</div>')
                ->setPaper('letter', 'landscape')
                ->output(),
        );

        return $path;
    }

    private function pageCount(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    public function test_renders_text_only_when_no_background_is_configured(): void
    {
        config(['certificates.background' => '/no/such/file.pdf']);

        $this->assertNull(CertificateRenderer::backgroundPath());

        $pdf = CertificateRenderer::pdf($this->certs(2));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, $this->pageCount($pdf));
    }

    public function test_merges_the_background_under_each_cert_page(): void
    {
        $bg = $this->makeBackgroundPdf();
        config(['certificates.background' => $bg]);

        try {
            $this->assertSame($bg, CertificateRenderer::backgroundPath());

            $pdf = CertificateRenderer::pdf($this->certs(3));

            // Still one page per certificate (background merged, not appended).
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(3, $this->pageCount($pdf));
        } finally {
            @unlink($bg);
        }
    }
}
