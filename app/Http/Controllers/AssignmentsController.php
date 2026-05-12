<?php

namespace App\Http\Controllers;

use App\Events\AssignmentCreated;
use App\Events\AssignmentDeleted;
use App\Events\AssignmentUpdated;
use App\Http\Requests\AssignmentRequest;
use App\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AssignmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Assignment::class);

        $query = Assignment::query()
            ->where('org_id', $request->user()->org_id);

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->query('user_id'));
        }
        if ($request->filled('requirement_id')) {
            $query->where('requirement_id', (string) $request->query('requirement_id'));
        }

        $rows = $query->orderBy('start_date', 'desc')->get();

        return response()->json($rows->map(fn (Assignment $a) => $this->serialize($a)));
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Assignment::class);

        $data = $request->validated();
        $assignment = Assignment::create([
            'org_id' => Auth::user()->org_id,
            'user_id' => $data['user_id'],
            'requirement_id' => $data['requirement_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
        ]);

        event(new AssignmentCreated($assignment, actorId: Auth::id()));

        return response()->json($this->serialize($assignment), 201);
    }

    public function update(AssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        $data = $request->validated();
        $assignment->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'initial_only' => (bool) $data['initial_only'],
            'repeating' => (bool) $data['repeating'],
            'std_freq_id' => ((bool) $data['repeating']) ? $data['std_freq_id'] : null,
            'as_needed' => (bool) $data['as_needed'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
        ]);

        event(new AssignmentUpdated($assignment->fresh()));

        return response()->json($this->serialize($assignment->fresh()));
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        Gate::authorize('delete', $assignment);

        $id = $assignment->id;
        $userId = $assignment->user_id;
        $reqId = $assignment->requirement_id;
        $orgId = $assignment->org_id;
        $assignment->delete();

        event(new AssignmentDeleted($id, $userId, $reqId, $orgId));

        return response()->json(['ok' => true]);
    }

    private function serialize(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'requirement_id' => $a->requirement_id,
            'name' => $a->name,
            'description' => $a->description,
            'initial_only' => $a->initial_only,
            'repeating' => $a->repeating,
            'std_freq_id' => $a->std_freq_id,
            'as_needed' => $a->as_needed,
            'start_date' => optional($a->start_date)->toDateString(),
            'end_date' => optional($a->end_date)->toDateString(),
            'can_edit' => Gate::check('update', $a),
            'can_delete' => Gate::check('delete', $a),
        ];
    }
}
