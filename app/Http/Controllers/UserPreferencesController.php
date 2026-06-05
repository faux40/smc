<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saves the authenticated user's UI preferences (table column visibility /
 * order + filter defaults). Self-only — always targets the actor, never a
 * user param — and stored as an opaque JSON blob the frontend owns. Shared
 * back on every page via the Inertia `auth.user.preferences` prop.
 */
class UserPreferencesController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
        ]);

        $user = $request->user();
        $user->preferences = $data['preferences'];
        $user->save();

        return response()->json(['ok' => true]);
    }
}
