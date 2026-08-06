<?php

namespace App\Http\Controllers;

use App\Events\AttachmentCreated;
use App\Events\AttachmentDeleted;
use App\Events\AttachmentUpdated;
use App\Models\Attachment;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttachmentsController extends Controller
{
    /**
     * Morphable whitelist. New HasAttachments consumers append here.
     */
    private const ALLOWED_ATTACHABLE_TYPES = [
        User::class,
        Training::class,
        Requirement::class,
        TrainingClass::class,
    ];

    private const STORAGE_DISK = 'linode';

    private const MAX_UPLOAD_KB = 25 * 1024; // 25 MB

    /**
     * Allowlisted upload extensions for Laravel's `mimes` rule, which checks
     * the *guessed* extension from the file's sniffed (magic-byte) MIME type
     * — not the client-declared one. Deliberately excludes anything
     * script-capable (notably SVG, which can carry inline <script>).
     */
    private const ALLOWED_UPLOAD_EXTENSIONS = 'pdf,png,jpg,jpeg,gif,webp,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,txt';

    /**
     * MIME types safe to render inline in the browser (raster images + PDF).
     * Everything else — including any legacy/unknown/absent mime — is served
     * with Content-Disposition: attachment so it can never execute as active
     * content from the storage origin.
     */
    private const INLINE_SAFE_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string', Rule::in(self::ALLOWED_ATTACHABLE_TYPES)],
            'attachable_id' => ['required', 'string'],
        ]);

        $this->authorizeSameOrgMorphable($data['attachable_type'], $data['attachable_id']);

        $attachments = Attachment::query()
            ->where('attachable_type', $data['attachable_type'])
            ->where('attachable_id', $data['attachable_id'])
            ->with('uploadedBy:id,f_name,l_name')
            ->orderByDesc('created_at')
            ->get(['id', 'org_id', 'attachable_type', 'attachable_id', 'uploaded_by_user_id', 'filename', 'type', 'description', 'mime', 'size', 'created_at']);

        return response()->json($attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'attachable_type' => $a->attachable_type,
            'attachable_id' => $a->attachable_id,
            'filename' => $a->filename,
            'type' => $a->type,
            'description' => $a->description,
            'mime' => $a->mime,
            'size' => $a->size,
            'uploaded_by_user_id' => $a->uploaded_by_user_id,
            'uploaded_by_name' => $a->uploadedBy?->name,
            'created_at' => $a->created_at?->toDateTimeString(),
            'can_delete' => Gate::check('delete', $a),
            'can_edit' => Gate::check('update', $a),
        ]));
    }

    /**
     * Distinct attachment "type" values already used in the caller's org —
     * feeds the upload form's type type-ahead so terms stay standardized
     * (the model's global org scope handles tenancy).
     */
    public function types(): JsonResponse
    {
        $types = Attachment::query()
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        return response()->json($types);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string', Rule::in(self::ALLOWED_ATTACHABLE_TYPES)],
            'attachable_id' => ['required', 'string'],
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'mimes:'.self::ALLOWED_UPLOAD_EXTENSIONS],
            // Optional uploader metadata: a free-text org vocabulary "type"
            // (e.g. "Sign-in sheet") and a freeform description.
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $morphable = $this->authorizeSameOrgMorphable($data['attachable_type'], $data['attachable_id']);
        Gate::authorize('create', [Attachment::class, $morphable]);

        $file = $request->file('file');
        // Generate a UUID-keyed path so original filenames don't collide.
        // We preserve the original filename in the DB row for display.
        $path = 'attachments/'.Str::uuid().'-'.$file->getClientOriginalName();
        $stored = Storage::disk(self::STORAGE_DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        // The linode disk is configured throw=false, so a storage outage
        // returns false rather than raising. Bail before the insert so we
        // never persist a row pointing at a blob that was never written.
        if ($stored === false) {
            return response()->json([
                'message' => 'File storage is temporarily unavailable. Please try again.',
            ], 503);
        }

        $attachment = Attachment::create([
            'org_id' => Auth::user()->org_id,
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => $data['attachable_id'],
            'uploaded_by_user_id' => Auth::id(),
            'filename' => $file->getClientOriginalName(),
            'type' => $data['type'] ?? null,
            'description' => $data['description'] ?? null,
            // Server-derived (magic-byte sniffed) MIME — never trust the
            // client-declared one, which is attacker-controlled.
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => self::STORAGE_DISK,
            'path' => $path,
        ]);

        event(new AttachmentCreated($attachment));

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
        ], 201);
    }

    /**
     * Edit an attachment's Type + Description. Policy gates this — notably,
     * once the parent (e.g. a class) is closed, only elevated roles may edit.
     */
    public function update(Request $request, Attachment $attachment): JsonResponse
    {
        Gate::authorize('update', $attachment);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $attachment->update([
            'type' => $data['type'] ?? null,
            'description' => $data['description'] ?? null,
        ]);

        event(new AttachmentUpdated($attachment));

        return response()->json([
            'id' => $attachment->id,
            'type' => $attachment->type,
            'description' => $attachment->description,
        ]);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        Gate::authorize('delete', $attachment);

        // Soft-delete only. Defer S3 cleanup to a future janitor; soft-delete
        // gives us a recovery window for accidental deletes.
        $id = $attachment->id;
        $orgId = $attachment->org_id;
        $attachment->delete();

        event(new AttachmentDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * 302-redirect to a signed temporary URL for the blob, forcing a download
     * (Content-Disposition: attachment). The frontend follows the redirect and
     * the browser fetches directly from Linode — the app server doesn't stream
     * bytes.
     */
    public function download(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('view', $attachment);

        return redirect()->away($this->signedBlobUrl($attachment, 'attachment'));
    }

    /**
     * 302-redirect to a signed temporary URL for the blob, served inline
     * (Content-Disposition: inline) so the embedded <AttachmentViewer> can
     * render PDFs/images in-place. Same offload model as download() — the app
     * authorizes and hands back a short-lived signed URL; it never streams the
     * bytes itself.
     */
    public function view(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('view', $attachment);

        return redirect()->away($this->signedBlobUrl($attachment, 'inline'));
    }

    /**
     * Mint a 5-minute signed URL for the blob with the given requested
     * disposition ('inline' to preview, 'attachment' to download). 'inline'
     * is only honored when the stored MIME is in the inline-safe allowlist
     * (raster images + PDF) — anything else (including a legacy/unknown/
     * absent mime) is downgraded to 'attachment' so it can never render as
     * active content (HTML/SVG/etc.) from the storage origin. A signed-URL
     * failure (object store unreachable) shouldn't surface as a 500 —
     * degrade to a clean "try again" the UI can show.
     */
    private function signedBlobUrl(Attachment $attachment, string $disposition): string
    {
        $effectiveDisposition = $disposition === 'inline' && in_array($attachment->mime, self::INLINE_SAFE_MIMES, true)
            ? 'inline'
            : 'attachment';

        $safeName = $this->sanitizeDispositionFilename((string) $attachment->filename);

        $options = [
            'ResponseContentDisposition' => $effectiveDisposition.'; filename="'.$safeName.'"',
        ];

        if ($attachment->mime) {
            $options['ResponseContentType'] = $attachment->mime;
        }

        try {
            return Storage::disk($attachment->disk)->temporaryUrl(
                $attachment->path,
                now()->addMinutes(5),
                $options,
            );
        } catch (\Throwable $e) {
            report($e);
            abort(503, 'File storage is temporarily unavailable. Please try again.');
        }
    }

    /**
     * Sanitize a user-supplied filename for embedding in a quoted
     * Content-Disposition parameter: strip control characters (CR/LF and
     * friends, which could otherwise inject extra header lines) and quotes
     * (which could break out of the quoted string), and fold non-ASCII down
     * to '_' since the bare `filename="..."` form isn't a safe carrier for
     * it.
     */
    private function sanitizeDispositionFilename(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = str_replace('"', '', $name);
        $name = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
    }

    private function authorizeSameOrgMorphable(string $type, string $id): Model
    {
        /** @var class-string<Model> $type */
        $morphable = $type::query()->withoutGlobalScope('organization')->find($id);
        abort_if($morphable === null, 404, 'Attachable not found.');
        abort_unless($morphable->org_id === Auth::user()->org_id, 403);

        return $morphable;
    }
}
