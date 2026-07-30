<?php

namespace App\Http\Controllers;

use App\Actions\SyncTrainingCardFields;
use App\Http\Requests\CardFieldsSyncRequest;
use App\Models\CardField;
use App\Models\Training;
use App\Support\Cards\CardFieldPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A training's custom card fields (custom-certs C3) — the `${keys}` its card
 * design can merge beyond the built-in catalogue.
 *
 * Admin+ throughout: this is vocabulary, not data entry. Managers fill the
 * values in on a class, and get the definitions they need embedded in the
 * class-detail payload rather than from here.
 */
class CardFieldsController extends Controller
{
    public function index(Training $training): JsonResponse
    {
        Gate::authorize('update', $training);

        return response()->json(
            $training->cardFields()->withCount('values')->get()
                ->map(fn (CardField $f) => $this->serialize($f))
        );
    }

    /**
     * Replace the whole set. See {@see SyncTrainingCardFields} for why this is
     * one call rather than add/remove/reorder.
     */
    public function sync(
        CardFieldsSyncRequest $request,
        Training $training,
        SyncTrainingCardFields $sync,
    ): JsonResponse {
        Gate::authorize('update', $training);

        $fields = $sync->handle($training, $request->validated()['fields']);

        return response()->json($fields->map(fn (CardField $f) => $this->serialize($f)));
    }

    /**
     * The definition plus how many class answers hang off it — the editor
     * names that number when confirming a removal, since removing a field
     * discards them. Kept out of CardFieldPresenter: class detail serves
     * definitions too, and there the count is neither loaded nor meaningful.
     *
     * @return array<string, mixed>
     */
    private function serialize(CardField $field): array
    {
        return [
            ...CardFieldPresenter::definition($field),
            'value_count' => (int) ($field->values_count ?? 0),
        ];
    }
}
