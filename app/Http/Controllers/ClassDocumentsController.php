<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\TrainingClass;
use App\Support\CertificateData;
use App\Support\ClassSignInSheet;
use App\Support\ClassSummary;
use App\Support\PdfRenderer;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Printable PDF documents for a class: certificates, sign-in sheet, class
 * summary. All rendered through PdfRenderer (Browsershot/Chromium) so they
 * share the Tailwind-styled pdf.layout.
 */
class ClassDocumentsController extends Controller
{
    public function certificates(TrainingClass $class): PdfBuilder
    {
        Gate::authorize('view', $class);

        $certs = CertificateData::forClass($class);

        abort_if($certs === [], 404, 'This class has no issued certificates.');

        return PdfRenderer::make('pdf.certificate', [
            'certs' => $certs,
            'background' => CertificateData::backgroundDataUri(),
        ])->name("certificates-{$class->id}.pdf");
    }

    /**
     * A single completion's certificate — usable for completions that never
     * came from a class (manual / imported). Content resolves from the class
     * snapshot when present, else from the training (see CertificateData).
     */
    public function completionCertificate(Completion $completion): PdfBuilder
    {
        Gate::authorize('view', $completion);

        return PdfRenderer::make('pdf.certificate', [
            'certs' => CertificateData::forCompletion($completion),
            'background' => CertificateData::backgroundDataUri(),
        ])->name("certificate-{$completion->id}.pdf");
    }

    public function signInSheet(TrainingClass $class): PdfBuilder
    {
        Gate::authorize('view', $class);

        return PdfRenderer::make('pdf.sign-in-sheet', ClassSignInSheet::data($class))
            ->name("sign-in-sheet-{$class->id}.pdf");
    }

    public function summary(TrainingClass $class): PdfBuilder
    {
        Gate::authorize('view', $class);

        return PdfRenderer::make('pdf.class-summary', ClassSummary::data($class))
            ->name("class-summary-{$class->id}.pdf");
    }
}
