<?php

namespace App\Http\Controllers;

use App\Events\AssignmentCreated;
use App\Events\AssignmentDeleted;
use App\Events\AssignmentUpdated;
use App\Http\Requests\AssignmentRequest;
use App\Models\Assignment;
use App\Models\Requirement;
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

        // Hide expired assignments by default: a past end_date means the
        // window has closed (matches the calculator's `end_date < today →
        // inactive`). end_date null = active forever; end_date today = still
        // its last active day. `?include_expired=1` opts back in (the page's
        // "show expired" toggle), where they render greyed + struck-through.
        if (! $request->boolean('include_expired')) {
            $query->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->query('user_id'));
        }
        if ($request->filled('requirement_id')) {
            $query->where('requirement_id', (string) $request->query('requirement_id'));
        }

        $rows = $query->with('requirement.elements')
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json($rows->map(fn (Assignment $a) => $this->serialize($a)));
    }

    public function store(AssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', Assignment::class);

        $data = $request->validated();

        // Name is a stable snapshot of the requirement at assign-time — the
        // server owns it (same as the bulk flow), so the client never sends
        // it. Description defaults to the requirement's but can be overridden.
        $requirement = Requirement::findOrFail($data['requirement_id']);

        $assignment = Assignment::create([
            'org_id' => Auth::user()->org_id,
            'user_id' => $data['user_id'],
            'requirement_id' => $requirement->id,
            'name' => $requirement->name,
            'description' => $data['description'] ?? $requirement->description,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
        ]);

        event(new AssignmentCreated($assignment, actorId: Auth::id()));

        return response()->json($this->serialize($assignment->load('requirement.elements')), 201);
    }

    public function update(AssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('update', $assignment);

        $data = $request->validated();
        // name stays the assign-time requirement snapshot — not editable.
        $assignment->update([
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
        ]);

        event(new AssignmentUpdated($assignment->fresh()));

        return response()->json($this->serialize($assignment->fresh()->load('requirement.elements')));
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
            'element_timing' => $a->requirement?->elementTimingSummary()
                ?? ['initial' => 0, 'repeating' => 0, 'as_needed' => 0, 'none' => 0],
            'start_date' => optional($a->start_date)->toDateString(),
            'end_date' => optional($a->end_date)->toDateString(),
            'can_edit' => Gate::check('update', $a),
            'can_delete' => Gate::check('delete', $a),
        ];
    }
}
