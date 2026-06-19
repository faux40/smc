<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Renders the certificate PDF and, when a background file is configured,
 * merges it UNDER the rendered text.
 *
 * The cert text is drawn by DomPDF on a transparent page; if a single-page
 * landscape-Letter background PDF exists (config `certificates.background`),
 * FPDI stamps each text page on top of that background as a vector template.
 * Reusing one imported template per page keeps memory flat across a large
 * class (vs. a CSS raster background, which re-embeds the image per page —
 * the failure mode behind the earlier prod cert outage).
 */
class CertificateRenderer
{
    /**
     * Build the final certificate PDF as a binary string.
     *
     * @param  array<int, array<string, mixed>>  $certs
     */
    public static function pdf(array $certs): string
    {
        self::prepareDompdfRuntime();

        $content = Pdf::loadView('pdf.certificate', ['certs' => $certs])
            ->setPaper('letter', 'landscape')
            ->output();

        $background = self::backgroundPath();

        return $background === null
            ? $content
            : self::mergeBackground($content, $background);
    }

    /** The configured background PDF path, or null when absent. */
    public static function backgroundPath(): ?string
    {
        $path = config('certificates.background');

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * Stamp every page of the rendered (transparent) cert content on top of
     * the single-page background, preserving each content page's size.
     */
    private static function mergeBackground(string $content, string $backgroundPath): string
    {
        $pdf = new Fpdi;

        // Import the background page once; the template is reusable across
        // pages even after the source file is switched to the content.
        $pdf->setSourceFile($backgroundPath);
        $backgroundTpl = $pdf->importPage(1);

        $pageCount = $pdf->setSourceFile(StreamReader::createByString($content));

        for ($page = 1; $page <= $pageCount; $page++) {
            $contentTpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($contentTpl);

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            // Background first (underneath), then the cert text on top.
            $pdf->useTemplate($backgroundTpl, 0, 0, $size['width'], $size['height']);
            $pdf->useTemplate($contentTpl, 0, 0, $size['width'], $size['height']);
        }

        return $pdf->Output('S');
    }

    /**
     * Ensure DomPDF's font-cache dir exists and the request has enough memory
     * to render a multi-page document. Only ever raises the memory limit.
     */
    private static function prepareDompdfRuntime(): void
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $current = self::memoryLimitBytes();

        if ($current !== -1 && $current < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
