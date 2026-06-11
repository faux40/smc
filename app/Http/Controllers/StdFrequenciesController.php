<?php

namespace App\Http\Controllers;

use App\Events\StdFrequencyCreated;
use App\Events\StdFrequencyDeleted;
use App\Events\StdFrequencyUpdated;
use App\Models\StdFrequency;
use App\Services\TrainingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StdFrequenciesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StdFrequency::class);

        $rows = StdFrequency::query()
            ->where('org_id', $request->user()->org_id)
            ->orderBy('repeat_days')
            ->get(['id', 'name', 'repeat_days']);

        return response()->json($rows->map(fn (StdFrequency $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'repeat_days' => $f->repeat_days,
            'can_edit' => Gate::check('update', $f),
            'can_delete' => Gate::check('delete', $f),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', StdFrequency::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'repeat_days' => ['required', 'integer', 'min:1'],
        ]);

        $freq = StdFrequency::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'repeat_days' => $data['repeat_days'],
        ]);

        event(new StdFrequencyCreated($freq));

        return response()->json([
            'id' => $freq->id,
            'name' => $freq->name,
            'repeat_days' => $freq->repeat_days,
        ], 201);
    }

    public function update(
        Request $request,
        StdFrequency $stdFrequency,
        TrainingAssignmentService $assignments,
    ): JsonResponse {
        Gate::authorize('update', $stdFrequency);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'repeat_days' => ['required', 'integer', 'min:1'],
        ]);

        $stdFrequency->update($data);

        // A new cycle length moves every TA timed by this frequency (J2).
        if ($stdFrequency->wasChanged('repeat_days')) {
            $assignments->refreshForStdFrequency($stdFrequency->org_id, $stdFrequency->id);
        }

        event(new StdFrequencyUpdated($stdFrequency->fresh()));

        return response()->json([
            'id' => $stdFrequency->id,
            'name' => $stdFrequency->name,
            'repeat_days' => $stdFrequency->repeat_days,
        ]);
    }

    public function destroy(
        StdFrequency $stdFrequency,
        TrainingAssignmentService $assignments,
    ): JsonResponse {
        Gate::authorize('delete', $stdFrequency);

        $id = $stdFrequency->id;
        $orgId = $stdFrequency->org_id;
        $stdFrequency->delete();

        // Trashed frequency → affected TAs fall back to no computed expiry (J2).
        $assignments->refreshForStdFrequency($orgId, $id);

        event(new StdFrequencyDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }
}
