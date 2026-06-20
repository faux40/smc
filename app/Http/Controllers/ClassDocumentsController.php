<?php

namespace App\Http\Controllers;

use App\Actions\FileClassDocument;
use App\Models\Completion;
use App\Models\TrainingClass;
use App\Support\CertificateData;
use App\Support\ClassSignInSheet;
use App\Support\ClassSummary;
use App\Support\PdfRenderer;
use Illuminate\Http\JsonResponse;
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

        return $this->certificatesPdf($certs)->name("certificates-{$class->id}.pdf");
    }

    /**
     * File a copy of the class's certificates PDF into the class's documents
     * (a TrainingClass attachment on Linode). The GET above is for viewing;
     * this POST persists a fresh, timestamped copy.
     */
    public function storeCertificates(TrainingClass $class, FileClassDocument $action): JsonResponse
    {
        Gate::authorize('view', $class);

        $certs = CertificateData::forClass($class);
        abort_if($certs === [], 422, 'This class has no issued certificates.');

        $attachment = $action->handle(
            $class,
            $this->certificatesPdf($certs),
            FileClassDocument::filename($class, 'Certificates'),
        );

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
        ], 201);
    }

    /**
     * File a copy of the class summary PDF into the class's documents.
     */
    public function storeSummary(TrainingClass $class, FileClassDocument $action): JsonResponse
    {
        Gate::authorize('view', $class);

        $attachment = $action->handle(
            $class,
            $this->summaryPdf($class),
            FileClassDocument::filename($class, 'Summary'),
        );

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
        ], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $certs
     */
    private function certificatesPdf(array $certs): PdfBuilder
    {
        return PdfRenderer::make('pdf.certificate', [
            'certs' => $certs,
            'background' => CertificateData::backgroundDataUri(),
        ]);
    }

    private function summaryPdf(TrainingClass $class): PdfBuilder
    {
        $data = ClassSummary::data($class);

        return $this->withReportFooter(PdfRenderer::make('pdf.class-summary', $data), $data);
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

        $data = ClassSignInSheet::data($class);

        return $this->withReportFooter(PdfRenderer::make('pdf.sign-in-sheet', $data), $data)
            ->name("sign-in-sheet-{$class->id}.pdf");
    }

    public function summary(TrainingClass $class): PdfBuilder
    {
        Gate::authorize('view', $class);

        return $this->summaryPdf($class)->name("class-summary-{$class->id}.pdf");
    }

    /**
     * Repeating page footer (generated-at + title) for the multi-page reports,
     * via Chromium's native header/footer so it sits in the page-bottom margin
     * and never overlaps content. An empty header suppresses Chromium's default.
     *
     * @param  array<string, mixed>  $data
     */
    private function withReportFooter(PdfBuilder $pdf, array $data): PdfBuilder
    {
        return $pdf
            ->headerHtml('<span></span>')
            ->footerView('pdf.partials.footer', [
                'stamp' => $data['generated_at'] ?? '',
                'title' => $data['title'] ?? '',
            ]);
    }
}
