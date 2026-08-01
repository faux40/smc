<?php

namespace App\Http\Controllers;

use App\Models\CardFont;
use App\Support\Cards\FontFile;
use App\Support\Cards\InvalidFontFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The org's uploaded font library (custom-certs C6c).
 *
 * LibreOffice embeds fonts into the exported PDF, but only fonts it can SEE.
 * A family that isn't installed gets substituted at conversion and the card
 * re-flows at different metrics — the failure that ruins a print onto
 * purchased stock. Uploading the file here lets the print run stage it where
 * the converter will find it.
 *
 * Listing is Manager+ (they print, and need to know why a warning cleared);
 * adding and removing is Admin+, and fonts never cross an org — licensing is
 * per org.
 */
class CardFontsController extends Controller
{
    private const DISK = 'linode';

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CardFont::class);

        $fonts = CardFont::query()->orderBy('family')->get();

        return response()->json($fonts->map(fn (CardFont $f) => $this->serialize($f)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CardFont::class);

        $request->validate([
            // The size cap is enforced here in KB (Laravel's unit) and stated
            // once on the model in bytes.
            'file' => ['required', 'file', 'max:'.(CardFont::MAX_BYTES / 1024)],
        ]);

        $upload = $request->file('file');

        /*
         * Read the family out of the FILE before anything is stored: the
         * filename is whatever the uploader typed, and a rejected upload must
         * leave nothing on the disk.
         */
        try {
            $font = FontFile::read($upload->getRealPath());
        } catch (InvalidFontFile $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $orgId = $request->user()->org_id;

        // Two files claiming one family would both be staged and LibreOffice
        // would pick whichever it liked — a card that prints differently on
        // different days. Replacing is deleting first, deliberately.
        $existing = CardFont::query()
            ->where('family_key', FontFile::normalise($font->family))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'file' => "“{$existing->family}” is already uploaded. Remove it first to replace it.",
            ]);
        }

        $path = Storage::disk(self::DISK)->putFileAs(
            "card-fonts/{$orgId}",
            $upload,
            (string) Str::uuid().'.'.$font->format,
        );

        $row = CardFont::create([
            'org_id' => $orgId,
            'family' => $font->family,
            'original_filename' => $upload->getClientOriginalName(),
            'format' => $font->format,
            'path' => $path,
            'size' => $upload->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($this->serialize($row), 201);
    }

    public function destroy(CardFont $cardFont): JsonResponse
    {
        Gate::authorize('delete', $cardFont);

        // The file goes with the row: unlike a replaced template version,
        // nothing refers back to a deleted font, so keeping the bytes would
        // only leave a licensed font sitting in storage.
        Storage::disk(self::DISK)->delete($cardFont->path);
        $cardFont->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CardFont $font): array
    {
        return [
            'id' => $font->id,
            'family' => $font->family,
            'original_filename' => $font->original_filename,
            'format' => $font->format,
            'size' => $font->size,
            'uploaded_at' => $font->created_at?->toIso8601String(),
            'can_delete' => Gate::check('delete', $font),
        ];
    }
}
