<?php

namespace App\Http\Controllers;

use App\Events\DocTemplatesChanged;
use App\Events\MergeFieldsChanged;
use App\Models\DocTemplate;
use App\Models\MergeField;
use App\Support\DocMerge\MergeDataBuilder;
use App\Support\DocMerge\TemplateTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Doc-template registry (Phase D2). Upload extracts the template's
 * `${key}` placeholders (split-run stitching included) and auto-registers
 * unknown keys as DRAFT org merge fields for the Admin to label; replace
 * chains a new version (old row soft-deleted, file kept so generation
 * history stays reproducible).
 */
class DocTemplatesController extends Controller
{
    private const DISK = 'linode';

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DocTemplate::class);

        $templates = DocTemplate::query()
            ->visibleTo($request->user()->org_id)
            ->orderBy('name')
            ->get();

        return response()->json($templates->map(fn (DocTemplate $t) => $this->serialize($t)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', DocTemplate::class);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $template = $this->storeUpload(
            $request,
            $data['file'],
            name: $data['name'],
            description: $data['description'] ?? null,
        );

        event(new DocTemplatesChanged($template->org_id));

        return response()->json($this->serialize($template), 201);
    }

    /**
     * Upload a new version of an existing template: new row, version+1,
     * prev_version_id chain; the old row is soft-deleted (file kept).
     */
    public function replace(Request $request, DocTemplate $docTemplate): JsonResponse
    {
        Gate::authorize('update', $docTemplate);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $template = $this->storeUpload(
            $request,
            $data['file'],
            name: $docTemplate->name,
            description: $docTemplate->description,
            version: $docTemplate->version + 1,
            prevVersionId: $docTemplate->id,
        );
        $docTemplate->delete();

        event(new DocTemplatesChanged($template->org_id));

        return response()->json($this->serialize($template), 201);
    }

    public function update(Request $request, DocTemplate $docTemplate): JsonResponse
    {
        Gate::authorize('update', $docTemplate);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $docTemplate->update($data);

        event(new DocTemplatesChanged($docTemplate->org_id));

        return response()->json($this->serialize($docTemplate->fresh()));
    }

    public function destroy(DocTemplate $docTemplate): JsonResponse
    {
        Gate::authorize('delete', $docTemplate);

        $orgId = $docTemplate->org_id;
        $docTemplate->delete(); // soft — the file stays for history

        event(new DocTemplatesChanged($orgId));

        return response()->json(['ok' => true]);
    }

    // ---- helpers ------------------------------------------------------

    private function storeUpload(
        Request $request,
        UploadedFile $file,
        string $name,
        ?string $description,
        int $version = 1,
        ?string $prevVersionId = null,
    ): DocTemplate {
        $extension = $this->validateTemplateFile($file);
        $orgId = $request->user()->org_id;

        // Extract ${keys} from the upload before it leaves local disk.
        $placeholders = (new TemplateTranslator)->findPlaceholders($file->getRealPath());

        $path = Storage::disk(self::DISK)->putFileAs(
            "doc-templates/{$orgId}",
            $file,
            (string) Str::uuid().'.'.$extension,
        );

        $template = DocTemplate::create([
            'org_id' => $orgId,
            'name' => $name,
            'description' => $description,
            'original_filename' => $file->getClientOriginalName(),
            'extension' => $extension,
            'path' => $path,
            'size' => $file->getSize(),
            'placeholders' => $placeholders,
            'version' => $version,
            'prev_version_id' => $prevVersionId,
            'uploaded_by' => $request->user()->id,
        ]);

        $this->registerDraftFields($orgId, $placeholders);

        return $template;
    }

    /**
     * Structural validation beats mime guessing here: the file must be a
     * zip containing the format's main document part. Returns 'docx'|'odt'.
     */
    private function validateTemplateFile(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        $fail = function (string $message): never {
            throw ValidationException::withMessages(['file' => $message]);
        };

        if (! in_array($extension, DocTemplate::EXTENSIONS, true)) {
            $fail('Templates must be .docx or .odt files.');
        }

        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $fail('The file is not a valid document archive.');
        }
        $mainPart = $extension === 'docx' ? 'word/document.xml' : 'content.xml';
        $valid = $zip->locateName($mainPart) !== false;
        $zip->close();

        if (! $valid) {
            $fail('The file is not a valid '.strtoupper($extension).' document.');
        }

        return $extension;
    }

    /**
     * Auto-register template keys with no matching field definition as
     * DRAFT org fields (plan decision 2026-07-11) — the Admin labels and
     * regroups them on the Document data page. Computed generation-time
     * keys and non-grammar tokens (legacy mixed-case aliases) are skipped.
     */
    private function registerDraftFields(string $orgId, array $placeholders): void
    {
        $known = MergeField::query()
            ->visibleTo($orgId)
            ->pluck('key')
            ->all();

        $unknown = collect($placeholders)
            ->filter(fn (string $key) => preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1)
            ->reject(fn (string $key) => in_array($key, MergeDataBuilder::COMPUTED_KEYS, true))
            ->reject(fn (string $key) => in_array($key, $known, true))
            ->values();

        if ($unknown->isEmpty()) {
            return;
        }

        foreach ($unknown as $key) {
            MergeField::create([
                'org_id' => $orgId,
                'key' => $key,
                'label' => Str::headline($key),
                'type' => 'text',
                'field_group' => 'From templates',
                'draft' => true,
            ]);
        }

        event(new MergeFieldsChanged($orgId));
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DocTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'original_filename' => $t->original_filename,
            'extension' => $t->extension,
            'size' => $t->size,
            'placeholders' => $t->placeholders,
            'version' => $t->version,
            'is_system' => $t->isSystem(),
            'can_edit' => Gate::check('update', $t),
            'can_delete' => Gate::check('delete', $t),
            'updated_at' => $t->updated_at?->toISOString(),
        ];
    }
}
