<?php

namespace App\Actions;

use App\Events\AttachmentCreated;
use App\Models\Attachment;
use App\Models\TrainingClass;
use App\Support\CertificateData;
use App\Support\PdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Render a completed class's certificates to a single PDF and file it as a
 * TrainingClass attachment on the Linode disk, so a copy lives in the class's
 * documents (and shows up in the class page's attachments list). A fresh,
 * timestamped copy is filed on each call.
 */
class StoreClassCertificates
{
    public function handle(TrainingClass $class): Attachment
    {
        $certs = CertificateData::forClass($class);

        abort_if($certs === [], 422, 'This class has no issued certificates.');

        $filename = self::filename($class);
        // UUID-keyed path so repeated saves never collide; the display name
        // (with the readable timestamp) lives on the Attachment row.
        $path = 'attachments/'.Str::uuid().'-'.$filename;

        PdfRenderer::make('pdf.certificate', [
            'certs' => $certs,
            'background' => CertificateData::backgroundDataUri(),
        ])->disk('linode')->save($path);

        $attachment = Attachment::create([
            'org_id' => $class->org_id,
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $class->id,
            'uploaded_by_user_id' => Auth::id(),
            'filename' => $filename,
            'mime' => 'application/pdf',
            'size' => null,
            'disk' => 'linode',
            'path' => $path,
        ]);

        event(new AttachmentCreated($attachment));

        return $attachment;
    }

    /**
     * `Certificates_<Class_Name>_<YYYYMMDD>_<HHMM>.pdf` — underscores only,
     * with a local-timezone date + time so each saved copy is identifiable.
     */
    public static function filename(TrainingClass $class): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $class->name), '_');
        $name = $name !== '' ? $name : 'Class';
        $stamp = Carbon::now(config('app.display_timezone'))->format('Ymd_Hi');

        return "Certificates_{$name}_{$stamp}.pdf";
    }
}
