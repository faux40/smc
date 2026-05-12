<?php

namespace App\Http\Controllers;

use App\Events\AttachmentCreated;
use App\Events\AttachmentDeleted;
use App\Models\Attachment;
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
    ];

    private const STORAGE_DISK = 'linode';

    private const MAX_UPLOAD_KB = 25 * 1024; // 25 MB

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
            ->get(['id', 'attachable_type', 'attachable_id', 'uploaded_by_user_id', 'filename', 'mime', 'size', 'created_at']);

        return response()->json($attachments->map(fn (Attachment $a) => [
            'id' => $a->id,
            'attachable_type' => $a->attachable_type,
            'attachable_id' => $a->attachable_id,
            'filename' => $a->filename,
            'mime' => $a->mime,
            'size' => $a->size,
            'uploaded_by_user_id' => $a->uploaded_by_user_id,
            'uploaded_by_name' => $a->uploadedBy?->name,
            'created_at' => $a->created_at?->toDateTimeString(),
            'can_delete' => Gate::check('delete', $a),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string', Rule::in(self::ALLOWED_ATTACHABLE_TYPES)],
            'attachable_id' => ['required', 'string'],
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB],
        ]);

        $this->authorizeSameOrgMorphable($data['attachable_type'], $data['attachable_id']);

        $file = $request->file('file');
        // Generate a UUID-keyed path so original filenames don't collide.
        // We preserve the original filename in the DB row for display.
        $path = 'attachments/'.Str::uuid().'-'.$file->getClientOriginalName();
        Storage::disk(self::STORAGE_DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        $attachment = Attachment::create([
            'org_id' => Auth::user()->org_id,
            'attachable_type' => $data['attachable_type'],
            'attachable_id' => $data['attachable_id'],
            'uploaded_by_user_id' => Auth::id(),
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
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
     * 302-redirect to a signed temporary URL for the blob. The frontend
     * follows the redirect and the browser fetches directly from Linode —
     * the app server doesn't stream bytes.
     */
    public function download(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('view', $attachment);

        $url = Storage::disk($attachment->disk)->temporaryUrl(
            $attachment->path,
            now()->addMinutes(5),
        );

        return redirect()->away($url);
    }

    private function authorizeSameOrgMorphable(string $type, string $id): void
    {
        /** @var class-string<Model> $type */
        $morphable = $type::query()->withoutGlobalScope('organization')->find($id);
        abort_if($morphable === null, 404, 'Attachable not found.');
        abort_unless($morphable->org_id === Auth::user()->org_id, 403);
    }
}
