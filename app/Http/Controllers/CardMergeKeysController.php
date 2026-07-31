<?php

namespace App\Http\Controllers;

use App\Models\CardTemplate;
use App\Support\Cards\CardMergeKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The built-in `${key}` catalogue, for the card designer to copy from
 * (custom-certs C4e).
 *
 * Served from {@see CardMergeKeys::GROUPS} — the same constant the merge reads
 * and the reserved-key rule checks — so what's listed here is exactly what
 * resolves at print time. A hand-kept copy in the frontend would be one
 * release away from promising a key that renders as literal text on a card.
 *
 * Gated with the template library: whoever may pick a design may also see the
 * vocabulary it's written in.
 */
class CardMergeKeysController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CardTemplate::class);

        $groups = collect(CardMergeKeys::GROUPS)
            ->map(fn (array $keys, string $group) => [
                'group' => $group,
                'keys' => array_map(fn (string $key) => [
                    'key' => $key,
                    // Rendered here rather than in the client for the same
                    // reason as CardFieldPresenter: one grammar, one place.
                    'placeholder' => '${'.$key.'}',
                ], $keys),
            ])
            ->values();

        return response()->json($groups);
    }
}
