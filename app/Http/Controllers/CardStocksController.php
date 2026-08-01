<?php

namespace App\Http\Controllers;

use App\Models\CardStock;
use App\Support\Cards\CalibrationSheet;
use App\Support\Cards\CardSheetGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Card stocks — the printable geometry of a purchased card sheet. Index
 * returns system + org stocks (Manager+ pick one when printing); CRUD
 * touches org stocks only, Admin+ (the policy blocks system rows).
 *
 * All measurements are points; the client converts for entry.
 */
class CardStocksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CardStock::class);

        $stocks = CardStock::query()
            ->visibleTo($request->user()->org_id)
            // System stocks first (the shipped layouts an org starts from),
            // then alphabetical.
            ->orderByRaw('org_id IS NOT NULL')
            ->orderBy('name')
            ->get();

        return response()->json($stocks->map(fn (CardStock $s) => $this->serialize($s)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CardStock::class);

        $data = $this->validated($request);

        $stock = CardStock::create([
            ...$data,
            'org_id' => $request->user()->org_id,
        ]);

        return response()->json($this->serialize($stock), 201);
    }

    public function update(Request $request, CardStock $cardStock): JsonResponse
    {
        Gate::authorize('update', $cardStock);

        $cardStock->update($this->validated($request));

        return response()->json($this->serialize($cardStock->fresh()));
    }

    public function destroy(CardStock $cardStock): JsonResponse
    {
        Gate::authorize('delete', $cardStock);

        $cardStock->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The printable calibration sheet (C6a) — cell outlines and measuring
     * marks for this stock, drawn with its current offsets so a reprint
     * after calibrating verifies the correction. `view`, not `update`:
     * Managers do the printing, and the sheet changes nothing.
     */
    public function calibrationSheet(CardStock $cardStock): BinaryFileResponse
    {
        Gate::authorize('view', $cardStock);

        $path = tempnam(sys_get_temp_dir(), 'cal').'.pdf';
        (new CalibrationSheet($cardStock))->render($path);

        return response()
            ->file($path, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend();
    }

    /**
     * Field rules plus the one rule that matters: the grid has to stay on
     * the page. Overflow is reported against column_count / row_count
     * because those are what the user usually mistyped, and the message
     * names the real culprit (any of card size, margin, gutter or count).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $measurement = ['required', 'numeric', 'min:0', 'max:99999'];
        $positive = ['required', 'numeric', 'gt:0', 'max:99999'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'page_width' => $positive,
            'page_height' => $positive,
            'card_width' => $positive,
            'card_height' => $positive,
            'column_count' => ['required', 'integer', 'min:1', 'max:100'],
            'row_count' => ['required', 'integer', 'min:1', 'max:100'],
            'margin_top' => $measurement,
            'margin_left' => $measurement,
            'gutter_x' => $measurement,
            'gutter_y' => $measurement,
            // The calibration nudge (C6a). ±72pt (1in) is far beyond any
            // real printer's drift — past that the number is almost
            // certainly a margin typed into the wrong box, and saying so
            // beats an inscrutable overflow error.
            'offset_x' => ['sometimes', 'numeric', 'between:-72,72'],
            'offset_y' => ['sometimes', 'numeric', 'between:-72,72'],
            'duplex_flip' => ['nullable', Rule::in(CardStock::DUPLEX_FLIPS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->assertGridFits($request, $data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertGridFits(Request $request, array $data): void
    {
        $geometry = new CardSheetGeometry(new CardStock($data));

        $offsetX = (float) ($data['offset_x'] ?? 0);
        $offsetY = (float) ($data['offset_y'] ?? 0);

        $overflow = [];

        /*
         * Each failure lands on the field that caused it: when the raw grid
         * already overflows, the offset is innocent and the count message
         * stands; when the grid fits and the nudge clips it — off the far
         * edge or past a margin on the near one — the offset is the culprit.
         */
        if ($geometry->usedWidth() > $data['page_width'] + 0.01) {
            $overflow['column_count'] = 'The columns, card width, left margin and horizontal gutter add up to more than the page width.';
        } elseif ($geometry->usedWidth() + $offsetX > $data['page_width'] + 0.01) {
            $overflow['offset_x'] = 'This shift pushes the right column off the page.';
        } elseif ($data['margin_left'] + $offsetX < -0.01) {
            $overflow['offset_x'] = 'This shift pulls the first column off the left edge.';
        }

        if ($geometry->usedHeight() > $data['page_height'] + 0.01) {
            $overflow['row_count'] = 'The rows, card height, top margin and vertical gutter add up to more than the page height.';
        } elseif ($geometry->usedHeight() + $offsetY > $data['page_height'] + 0.01) {
            $overflow['offset_y'] = 'This shift pushes the bottom row off the page.';
        } elseif ($data['margin_top'] + $offsetY < -0.01) {
            $overflow['offset_y'] = 'This shift pulls the first row off the top edge.';
        }

        if ($overflow !== []) {
            throw ValidationException::withMessages($overflow);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CardStock $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'page_width' => $s->page_width,
            'page_height' => $s->page_height,
            'column_count' => $s->column_count,
            'row_count' => $s->row_count,
            'card_width' => $s->card_width,
            'card_height' => $s->card_height,
            'margin_top' => $s->margin_top,
            'margin_left' => $s->margin_left,
            'gutter_x' => $s->gutter_x,
            'gutter_y' => $s->gutter_y,
            'offset_x' => $s->offset_x,
            'offset_y' => $s->offset_y,
            'duplex_flip' => $s->duplex_flip,
            'notes' => $s->notes,
            // Derived, never stored — one grid calculation, shared with the
            // imposition step.
            'per_sheet' => (new CardSheetGeometry($s))->perSheet(),
            'is_system' => $s->isSystem(),
            'can_edit' => Gate::check('update', $s),
            'can_delete' => Gate::check('delete', $s),
        ];
    }
}
