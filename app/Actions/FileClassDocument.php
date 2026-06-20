<?php

namespace App\Actions;

use App\Events\AttachmentCreated;
use App\Models\Attachment;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * File a generated class document (certificates, summary, …) as a copy in the
 * class's attachments on the Linode disk — so it lives alongside the class's
 * other documents and shows in the attachments list. The caller builds the
 * PdfBuilder (it owns the view/data); this just saves it + records the row.
 * A fresh, timestamped copy is filed on each call.
 */
class FileClassDocument
{
    public function handle(
        TrainingClass $class,
        PdfBuilder $pdf,
        string $filename,
        ?string $type = null,
        ?string $description = null,
    ): Attachment {
        // UUID-keyed path so repeated saves never collide; the display name
        // (with the readable timestamp) lives on the Attachment row.
        $path = 'attachments/'.Str::uuid().'-'.$filename;

        $pdf->disk('linode')->save($path);

        $attachment = Attachment::create([
            'org_id' => $class->org_id,
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $class->id,
            'uploaded_by_user_id' => Auth::id(),
            'filename' => $filename,
            'type' => $type,
            'description' => $description,
            'mime' => 'application/pdf',
            'size' => null,
            'disk' => 'linode',
            'path' => $path,
        ]);

        event(new AttachmentCreated($attachment));

        return $attachment;
    }

    /**
     * `<Prefix>_<Class_Name>_<YYYYMMDD>_<HHMM>.pdf` — underscores only, with a
     * local-timezone date + time so each saved copy is identifiable.
     */
    public static function filename(TrainingClass $class, string $prefix): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $class->name), '_');
        $name = $name !== '' ? $name : 'Class';
        $stamp = Carbon::now(config('app.display_timezone'))->format('Ymd_Hi');

        return "{$prefix}_{$name}_{$stamp}.pdf";
    }
}
