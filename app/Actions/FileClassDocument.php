<?php

namespace App\Actions;

use App\Events\AttachmentCreated;
use App\Models\Attachment;
use App\Models\TrainingClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
    private const DISK = 'linode';

    public function handle(
        TrainingClass $class,
        PdfBuilder $pdf,
        string $filename,
        ?string $type = null,
        ?string $description = null,
    ): Attachment {
        $path = $this->pathFor($filename);

        $pdf->disk(self::DISK)->save($path);

        // Size stays null: the builder has only just written the file, and the
        // documents list doesn't need it badly enough to stat the disk.
        return $this->record($class, $path, $filename, $type, $description, null);
    }

    /**
     * File a PDF that already exists on the local filesystem — a queued job's
     * output (custom-certs C4: merged, converted and imposed card sheets)
     * rather than something rendered on the spot.
     */
    public function fromPath(
        TrainingClass $class,
        string $sourcePath,
        string $filename,
        ?string $type = null,
        ?string $description = null,
        ?string $uploadedByUserId = null,
    ): Attachment {
        if (! is_file($sourcePath)) {
            throw new \InvalidArgumentException("No file to file at {$sourcePath}.");
        }

        // attachments.uploaded_by_user_id is NOT NULL, and a queue worker has
        // no authenticated user — so the caller passes the person who asked
        // for the run. Refused here rather than as a constraint violation
        // three frames deeper.
        $uploader = $uploadedByUserId ?? Auth::id();

        if ($uploader === null) {
            throw new \InvalidArgumentException(
                'An uploader is required: attachments record who filed them.',
            );
        }

        $size = filesize($sourcePath);
        $path = $this->pathFor($filename);

        // Streamed rather than read whole: a 200-card run is a big sheet PDF,
        // and this runs on a queue worker whose memory limit isn't generous.
        $stream = fopen($sourcePath, 'rb');

        try {
            Storage::disk(self::DISK)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->record($class, $path, $filename, $type, $description, $size ?: null, $uploader);
    }

    /**
     * UUID-keyed path so repeated saves never collide — reprints are normal,
     * and a second run must not overwrite the first. The display name (with
     * the readable timestamp) lives on the Attachment row.
     */
    private function pathFor(string $filename): string
    {
        return 'attachments/'.Str::uuid().'-'.$filename;
    }

    /**
     * The attachment row + the event the documents list refreshes off, shared
     * by both entry points so a queued job's output arrives exactly like a
     * synchronous one's.
     */
    private function record(
        TrainingClass $class,
        string $path,
        string $filename,
        ?string $type,
        ?string $description,
        ?int $size,
        ?string $uploadedByUserId = null,
    ): Attachment {
        $attachment = Attachment::create([
            'org_id' => $class->org_id,
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $class->id,
            'uploaded_by_user_id' => $uploadedByUserId ?? Auth::id(),
            'filename' => $filename,
            'type' => $type,
            'description' => $description,
            'mime' => 'application/pdf',
            'size' => $size,
            'disk' => self::DISK,
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
