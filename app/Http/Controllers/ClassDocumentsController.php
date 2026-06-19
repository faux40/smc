<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\TrainingClass;
use App\Support\CertificateData;
use App\Support\CertificateRenderer;
use App\Support\ClassSignInSheet;
use App\Support\ClassSummary;
use Barryvdh\DomPDF\Facade\Pdf;
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

        $certs = CertificateData::forClass($class);

        abort_if($certs === [], 404, 'This class has no issued certificates.');

        return $this->streamPdf(CertificateRenderer::pdf($certs), "certificates-{$class->id}.pdf");
    }

    /**
     * A single completion's certificate — usable for completions that never
     * came from a class (manual / imported). Content resolves from the class
     * snapshot when present, else from the training (see CertificateData).
     */
    public function completionCertificate(Completion $completion): Response
    {
        Gate::authorize('view', $completion);

        return $this->streamPdf(
            CertificateRenderer::pdf(CertificateData::forCompletion($completion)),
            "certificate-{$completion->id}.pdf",
        );
    }

    /** Stream a generated PDF string inline. */
    private function streamPdf(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
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
}
