<?php

namespace App\Http\Controllers;

use App\Events\TrainingCreated;
use App\Events\TrainingDeleted;
use App\Events\TrainingUpdated;
use App\Http\Requests\TrainingRequest;
use App\Models\Training;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TrainingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Training::class);

        $rows = Training::query()
            ->where('org_id', $request->user()->org_id)
            ->with('stdFrequency:id,name,repeat_days')
            ->orderBy('name')
            ->get();

        return response()->json($rows->map(fn (Training $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'initial_only' => $t->initial_only,
            'repeating' => $t->repeating,
            'std_freq_id' => $t->std_freq_id,
            'std_freq_name' => $t->stdFrequency?->name,
            'as_needed' => $t->as_needed,
            'default_hours' => $t->default_hours,
            'can_edit' => Gate::check('update', $t),
            'can_delete' => Gate::check('delete', $t),
        ]));
    }

    public function store(TrainingRequest $request): JsonResponse
    {
        Gate::authorize('create', Training::class);

        $data = $request->validated();
        $training = Training::create([
            'org_id' => $request->user()->org_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_hours' => $data['default_hours'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            // Null when not repeating; the validator already required it when repeating=true.
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
        ]);

        event(new TrainingCreated($training));

        return response()->json($this->serialize($training), 201);
    }

    public function update(TrainingRequest $request, Training $training): JsonResponse
    {
        Gate::authorize('update', $training);

        $data = $request->validated();
        $training->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'default_hours' => $data['default_hours'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
        ]);

        event(new TrainingUpdated($training->fresh()));

        return response()->json($this->serialize($training->fresh()));
    }

    public function destroy(Training $training): JsonResponse
    {
        Gate::authorize('delete', $training);

        $id = $training->id;
        $orgId = $training->org_id;
        $training->delete();

        event(new TrainingDeleted($id, $orgId));

        return response()->json(['ok' => true]);
    }

    private function serialize(Training $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'initial_only' => $t->initial_only,
            'repeating' => $t->repeating,
            'std_freq_id' => $t->std_freq_id,
            'as_needed' => $t->as_needed,
            'default_hours' => $t->default_hours,
        ];
    }
}
