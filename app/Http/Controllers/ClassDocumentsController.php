<?php

namespace App\Http\Controllers;

use App\Models\TrainingClass;
use App\Support\ClassCertificates;
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

        $certs = ClassCertificates::rows($class);

        abort_if($certs === [], 404, 'This class has no issued certificates.');

        $pdf = Pdf::loadView('pdf.certificate', ['certs' => $certs])
            ->setPaper('letter', 'landscape');

        return $pdf->stream("certificates-{$class->id}.pdf");
    }
}
