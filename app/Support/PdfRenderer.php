<?php

namespace App\Support;

use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * One entry point for every generated PDF (certificate, sign-in sheet, class
 * summary). Renders a Blade view with Chromium via Browsershot so the
 * documents can be styled with the app's real Tailwind CSS; the per-page size
 * is driven by each view's `@page` rule (preferCSSPageSize).
 */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $view, array $data = []): PdfBuilder
    {
        return Pdf::view($view, $data)
            ->withBrowsershot(self::configure(...));
    }

    private static function configure(Browsershot $browsershot): void
    {
        $browsershot
            ->setNodeBinary(config('pdf.node_binary'))
            ->setNodeModulePath(config('pdf.node_modules'))
            ->setChromePath(config('pdf.chrome_path'))
            // Let each view's CSS `@page { size: … }` decide paper + orientation.
            ->setOption('preferCSSPageSize', true);

        if (config('pdf.no_sandbox')) {
            $browsershot->noSandbox();
        }
    }

    /**
     * The compiled Tailwind stylesheet, inlined into the PDF layout so Chromium
     * styles the document offline (no dev-server / asset round-trip). Cached
     * per request; returns '' if the build manifest is missing.
     */
    public static function tailwindCss(): string
    {
        static $css = null;

        if ($css !== null) {
            return $css;
        }

        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            return $css = '';
        }

        $entries = json_decode((string) file_get_contents($manifest), true);
        $file = $entries['resources/css/app.css']['file'] ?? null;
        $cssPath = $file ? public_path('build/'.$file) : null;

        return $css = ($cssPath && is_file($cssPath)) ? (string) file_get_contents($cssPath) : '';
    }
}
