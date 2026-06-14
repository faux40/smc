<?php

namespace App\Http\Controllers;

use App\Models\TrainingClass;
use App\Support\ClassCertificates;
use App\Support\ClassSignInSheet;
use App\Support\ClassSummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable PDF documents for a class: certificates (and, later, the sign-in
 * sheet + class summary). Rendered with DomPDF (pure PHP — no headless
 * browser needed).
 */
class ClassDocumentsController extends Controller
{
    public function certificates(TrainingClass $class): Response
    {
        Gate::authorize('view', $class);

        $certs = ClassCertificates::rows($class);

        abort_if($certs === [], 404, 'This class has no issued certificates.');

        // Prod hardening: the certificate is the only PDF using a custom
        // @font-face (the GreatVibes signature), which DomPDF caches to
        // storage/fonts on first render — make sure that dir exists. And give
        // a generous memory ceiling so a large class (many cert pages) can't
        // exhaust a tight php-fpm limit.
        $this->prepareDompdfRuntime();

        $pdf = Pdf::loadView('pdf.certificate', ['certs' => $certs])
            ->setPaper('letter', 'landscape');

        return $pdf->stream("certificates-{$class->id}.pdf");
    }

    public function signInSheet(TrainingClass $class): Response
    {
        Gate::authorize('view', $class);

        $pdf = Pdf::loadView('pdf.sign-in-sheet', ClassSignInSheet::data($class))
            ->setPaper('letter', 'portrait');

        return $pdf->stream("sign-in-sheet-{$class->id}.pdf");
    }

    public function summary(TrainingClass $class): Response
    {
        Gate::authorize('view', $class);

        $pdf = Pdf::loadView('pdf.class-summary', ClassSummary::data($class))
            ->setPaper('letter', 'portrait');

        return $pdf->stream("class-summary-{$class->id}.pdf");
    }

    /**
     * Ensure DomPDF's font cache dir exists and the request has enough memory
     * to render a multi-page document. Only ever raises the memory limit.
     */
    private function prepareDompdfRuntime(): void
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $current = $this->memoryLimitBytes();

        if ($current !== -1 && $current < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private function memoryLimitBytes(): int
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
