<?php

namespace App\Http\Controllers;

use App\Events\GeneratedDocumentsChanged;
use App\Jobs\GenerateDocument;
use App\Models\DocTemplate;
use App\Models\GeneratedDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generated documents (Phase D2) — the org's output archive. Generation
 * is queued (soffice is heavy); the row carries status and peer tabs
 * learn completion via the coarse broadcast.
 */
class GeneratedDocumentsController extends Controller
{
    private const DISK = 'linode';

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GeneratedDocument::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $page = GeneratedDocument::query()
            ->where('org_id', $request->user()->org_id)
            ->with(['template' => fn ($q) => $q->select('id', 'name', 'extension'), 'requestedBy:id,f_name,l_name'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (GeneratedDocument $d) => $this->serialize($d)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', GeneratedDocument::class);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'doc_template_id' => ['required', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
        ]);

        $template = DocTemplate::query()->find($data['doc_template_id']);
        if ($template === null || ($template->org_id !== null && $template->org_id !== $orgId)) {
            throw ValidationException::withMessages([
                'doc_template_id' => 'Unknown template.',
            ]);
        }

        $org = $request->user()->organization;
        $doc = GeneratedDocument::create([
            'org_id' => $orgId,
            'doc_template_id' => $template->id,
            'requested_by' => $request->user()->id,
            'location' => $data['location'] ?? '',
            'department' => $data['department'] ?? '',
            'status' => 'queued',
            // Demo naming convention: {Org}.{Template}_{Ymd}.
            'filename' => Str::slug($org->name, '_').'.'.Str::slug($template->name, '_')
                .'_'.now($org->timezone ?? 'UTC')->format('Ymd'),
        ]);

        GenerateDocument::dispatch($doc->id);

        event(new GeneratedDocumentsChanged($orgId));

        return response()->json($this->serialize($doc->load('template')), 201);
    }

    /**
     * Redirect to a short-lived signed URL for the requested output.
     */
    public function download(Request $request, GeneratedDocument $generatedDocument): RedirectResponse
    {
        Gate::authorize('view', $generatedDocument);

        $format = $request->query('format', 'pdf');
        $path = $format === 'merged' ? $generatedDocument->merged_path : $generatedDocument->pdf_path;

        abort_if($generatedDocument->status !== 'done' || $path === null, 409, 'Document is not ready.');

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return redirect()->away(
            Storage::disk(self::DISK)->temporaryUrl(
                $path,
                now()->addMinutes(15),
                [
                    'ResponseContentDisposition' => 'attachment; filename="'.$generatedDocument->filename.'.'.$extension.'"',
                ],
            ),
        );
    }

    /**
     * Re-queue a failed run in place. The row keeps its identity — filename,
     * location/department variation and template — so a retry reproduces
     * exactly what was originally asked for; deleting and re-picking from the
     * generate bar loses the variation.
     */
    public function retry(GeneratedDocument $generatedDocument): JsonResponse
    {
        Gate::authorize('retry', $generatedDocument);

        abort_if(
            $generatedDocument->status !== 'failed',
            409,
            'Only a failed document can be retried.',
        );

        // The relation loads `withTrashed()`, so a *superseded* template still
        // resolves and still reproduces. Null means hard-deleted (the FK is
        // nullOnDelete, which orphans the row rather than removing it), and
        // GenerateDocument early-returns on a null template *before* it marks
        // the row processing — queueing one would park it at 'queued'
        // forever, which is a worse lie than the failure it replaced.
        abort_if(
            $generatedDocument->template === null,
            409,
            'The template this document was generated from no longer exists.',
        );

        $generatedDocument->update(['status' => 'queued', 'error' => null]);

        GenerateDocument::dispatch($generatedDocument->id);

        event(new GeneratedDocumentsChanged($generatedDocument->org_id));

        return response()->json($this->serialize($generatedDocument));
    }

    public function destroy(GeneratedDocument $generatedDocument): JsonResponse
    {
        Gate::authorize('delete', $generatedDocument);

        $orgId = $generatedDocument->org_id;
        foreach ([$generatedDocument->merged_path, $generatedDocument->pdf_path] as $path) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
        $generatedDocument->delete();

        event(new GeneratedDocumentsChanged($orgId));

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(GeneratedDocument $d): array
    {
        return [
            'id' => $d->id,
            'template_id' => $d->doc_template_id,
            'template_name' => $d->template?->name,
            'extension' => $d->template?->extension,
            'location' => $d->location,
            'department' => $d->department,
            'status' => $d->status,
            'error' => $d->error,
            'filename' => $d->filename,
            'requested_by_name' => $d->relationLoaded('requestedBy') ? $d->requestedBy?->name : null,
            'created_at' => $d->created_at?->toISOString(),
            // For a failed row this IS the moment it failed — without it the
            // UI cannot date the error, and a stale failure reads as a live
            // one (prod, 2026-08-04).
            'updated_at' => $d->updated_at?->toISOString(),
        ];
    }
}
