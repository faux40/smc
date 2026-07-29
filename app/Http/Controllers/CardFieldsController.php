<?php

namespace App\Http\Controllers;

use App\Actions\SyncTrainingCardFields;
use App\Http\Requests\CardFieldsSyncRequest;
use App\Models\CardField;
use App\Models\Training;
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
            $training->cardFields()->get()->map(fn (CardField $f) => $this->serialize($f))
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
     * @return array<string, mixed>
     */
    private function serialize(CardField $f): array
    {
        return [
            'id' => $f->id,
            'key' => $f->key,
            // What the author actually types into the slide.
            'placeholder' => $f->placeholder(),
            'label' => $f->label,
            'type' => $f->type,
            'default_value' => $f->default_value,
            'max_length' => $f->maxLength(),
            'seq' => $f->seq,
        ];
    }
}
