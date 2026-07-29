<?php

namespace App\Http\Controllers;

use App\Models\CardTemplate;
use App\Support\Cards\CardTemplateFile;
use App\Support\Cards\InvalidCardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Card-template registry (custom-certs C2) — the uploaded PPTX/ODP card
 * designs. Index is Manager+ (they print from these); upload/replace/rename/
 * delete is Admin+ and org templates only.
 *
 * A template is ONE card: slide 1 the front, an optional slide 2 the back.
 * Everything else about it — card size, sides, `${keys}`, fonts — is read
 * from the archive rather than typed, so the record cannot disagree with the
 * file it describes.
 */
class CardTemplatesController extends Controller
{
    private const DISK = 'linode';

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CardTemplate::class);

        $templates = CardTemplate::query()
            ->visibleTo($request->user()->org_id)
            ->orderByRaw('org_id IS NOT NULL')
            ->orderBy('name')
            ->get();

        return response()->json($templates->map(fn (CardTemplate $t) => $this->serialize($t)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CardTemplate::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $template = $this->storeUpload(
            $request,
            $request->file('file'),
            $data['name'],
            $data['description'] ?? null,
        );

        return response()->json($this->serialize($template), 201);
    }

    /**
     * Swap the file, keeping the name: a new row chained to the old one,
     * which is soft-deleted with its file intact so past prints stay
     * explicable. The card size and side count are re-read — a replacement
     * may legitimately add a back.
     */
    public function replace(Request $request, CardTemplate $cardTemplate): JsonResponse
    {
        Gate::authorize('update', $cardTemplate);

        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $replacement = $this->storeUpload(
            $request,
            $request->file('file'),
            $cardTemplate->name,
            $cardTemplate->description,
            $cardTemplate->version + 1,
            $cardTemplate->id,
        );

        $cardTemplate->delete(); // soft — the file stays for history

        return response()->json($this->serialize($replacement));
    }

    public function update(Request $request, CardTemplate $cardTemplate): JsonResponse
    {
        Gate::authorize('update', $cardTemplate);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $cardTemplate->update($data);

        return response()->json($this->serialize($cardTemplate->fresh()));
    }

    public function destroy(CardTemplate $cardTemplate): JsonResponse
    {
        Gate::authorize('delete', $cardTemplate);

        $cardTemplate->delete(); // soft — the file stays for history

        return response()->json(['ok' => true]);
    }

    // ---- helpers ---------------------------------------------------------

    private function storeUpload(
        Request $request,
        UploadedFile $file,
        string $name,
        ?string $description,
        int $version = 1,
        ?string $prevVersionId = null,
    ): CardTemplate {
        $extension = strtolower($file->getClientOriginalExtension());

        // Inspect BEFORE storing: a rejected upload must leave nothing on
        // the disk.
        try {
            $info = CardTemplateFile::inspect($file->getRealPath(), $extension);
        } catch (InvalidCardTemplate $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $orgId = $request->user()->org_id;

        $path = Storage::disk(self::DISK)->putFileAs(
            "card-templates/{$orgId}",
            $file,
            (string) Str::uuid().'.'.$extension,
        );

        return CardTemplate::create([
            'org_id' => $orgId,
            'name' => $name,
            'description' => $description,
            'original_filename' => $file->getClientOriginalName(),
            'extension' => $extension,
            'path' => $path,
            'size' => $file->getSize(),
            // Deliberately NOT auto-registered as draft org merge fields the
            // way doc-template keys are: a card's vocabulary comes from the
            // class/user catalogue and the training's own custom fields, so
            // an unknown key here is a typo, not a new field to define.
            'placeholders' => $info->placeholders,
            'fonts' => $info->fonts,
            'unsupported_fonts' => $info->unsupportedFonts(),
            'slide_count' => $info->slideCount,
            'card_width' => $info->cardWidth,
            'card_height' => $info->cardHeight,
            'version' => $version,
            'prev_version_id' => $prevVersionId,
            'uploaded_by' => $request->user()->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CardTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'original_filename' => $t->original_filename,
            'extension' => $t->extension,
            'size' => $t->size,
            'placeholders' => $t->placeholders,
            'fonts' => $t->fonts,
            // Families LibreOffice would substitute — the card would re-flow
            // at different metrics, which is what ruins a print.
            'unsupported_fonts' => $t->unsupported_fonts,
            'slide_count' => $t->slide_count,
            'has_back' => $t->hasBack(),
            'card_width' => $t->card_width,
            'card_height' => $t->card_height,
            'version' => $t->version,
            'is_system' => $t->isSystem(),
            'can_edit' => Gate::check('update', $t),
            'can_delete' => Gate::check('delete', $t),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
