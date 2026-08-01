<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateCardSheets;
use App\Models\CardPrintRun;
use App\Models\CardStock;
use App\Models\TrainingClass;
use App\Support\Cards\CardSheetGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Printing a class topic's cards (custom-certs C4d).
 *
 * The request only chooses *what* to print — design, stock, where on the sheet
 * to start — and a queued job does the work. So the job here is to reject an
 * unprintable combination now, while the user is looking at the form, rather
 * than letting them discover it later as a failed run.
 *
 * Gated on `view` like the certificate endpoints: printing from a completed
 * class is the main case, not an exception to it.
 */
class CardPrintRunsController extends Controller
{
    public function index(TrainingClass $class): JsonResponse
    {
        Gate::authorize('view', $class);

        $runs = CardPrintRun::query()
            ->where('class_id', $class->id)
            ->with('topic:id,training_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json($runs->map(fn (CardPrintRun $r) => $this->serialize($r)));
    }

    public function store(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('view', $class);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'class_training_id' => [
                'required', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
            // Optional: the print-time override. Falls back to the design the
            // training carries.
            'card_template_id' => [
                'nullable', 'string',
                Rule::exists('card_templates', 'id')
                    ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId))
                    ->whereNull('deleted_at'),
            ],
            'card_stock_id' => [
                'required', 'string',
                Rule::exists('card_stocks', 'id')
                    ->where(fn ($q) => $q->whereNull('org_id')->orWhere('org_id', $orgId))
                    ->whereNull('deleted_at'),
            ],
            'start_cell' => ['required', 'integer', 'min:1'],
            'include_backs' => ['boolean'],
        ]);

        $topic = $class->classTrainings()->with('training')->findOrFail($data['class_training_id']);

        $templateId = $data['card_template_id'] ?? $topic->training?->card_template_id;

        if ($templateId === null) {
            throw ValidationException::withMessages([
                'card_template_id' => 'This training has no card design. Assign one on the training, or pick one for this print run.',
            ]);
        }

        // The stock's grid decides how many cells a sheet has, so the ceiling
        // can only be checked once the stock is known.
        $stock = CardStock::query()
            ->visibleTo($orgId)
            ->findOrFail($data['card_stock_id']);
        $perSheet = (new CardSheetGeometry($stock))->perSheet();

        if ($data['start_cell'] > $perSheet) {
            throw ValidationException::withMessages([
                'start_cell' => "This stock has {$perSheet} cards per sheet.",
            ]);
        }

        $run = CardPrintRun::create([
            'org_id' => $orgId,
            'class_id' => $class->id,
            'class_training_id' => $topic->id,
            'card_template_id' => $templateId,
            'card_stock_id' => $stock->id,
            'start_cell' => $data['start_cell'],
            'include_backs' => (bool) ($data['include_backs'] ?? false),
            'status' => 'queued',
            'requested_by' => $request->user()->id,
        ]);

        GenerateCardSheets::dispatch($run->id);

        return response()->json($this->serialize($run), 202);
    }

    /**
     * Clear a run from the class's list.
     *
     * The record goes; the sheets it filed do not. Those are class documents
     * with their own delete, and they are the printed artifact — tidying up
     * the note that a run happened must never take the output with it.
     */
    public function destroy(TrainingClass $class, string $cardPrintRun): JsonResponse
    {
        Gate::authorize('view', $class);

        // Scoped to the class in the route rather than resolved globally: a
        // run id from another class must 404 here, not delete.
        CardPrintRun::query()
            ->where('class_id', $class->id)
            ->findOrFail($cardPrintRun)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CardPrintRun $run): array
    {
        return [
            'id' => $run->id,
            'class_training_id' => $run->class_training_id,
            'topic_name' => $run->topic?->training_name,
            'status' => $run->status,
            // Why a run failed is the whole reason for showing runs at all.
            'error' => $run->error,
            'card_count' => $run->card_count,
            'sheet_count' => $run->sheet_count,
            'include_backs' => $run->include_backs,
            'start_cell' => $run->start_cell,
            // Deliberately no storage paths: the sheets are filed as class
            // documents, and that list is how they're downloaded.
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
