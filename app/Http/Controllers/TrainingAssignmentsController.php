<?php

namespace App\Http\Controllers;

use App\Events\TrainingAssignmentDeleted;
use App\Http\Requests\TrainingAssignmentRequest;
use App\Models\AssignmentSource;
use App\Models\TrainingAssignment;
use App\Services\TrainingAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TrainingAssignmentsController extends Controller
{
    public function __construct(
        private TrainingAssignmentService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TrainingAssignment::class);

        $query = TrainingAssignment::query()
            ->where('org_id', $request->user()->org_id)
            ->with(['activeSources']);

        if ($request->filled('user_id')) {
            $query->where('user_id', (string) $request->query('user_id'));
        }
        if ($request->filled('training_id')) {
            $query->where('training_id', (string) $request->query('training_id'));
        }

        $rows = $query->orderBy('created_at', 'desc')->get();

        return response()->json($rows->map(fn (TrainingAssignment $ta) => $this->serialize($ta)));
    }

    public function store(TrainingAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', TrainingAssignment::class);

        $data = $request->validated();
        $orgId = Auth::user()->org_id;
        $userId = $data['user_id'];

        $results = $data['source_type'] === 'requirement'
            ? $this->service->assignFromRequirement($orgId, $userId, $data['requirement_id'])
            : [$this->service->assignDirect($orgId, $userId, $data['training_id'])];

        return response()->json(
            array_map(fn (TrainingAssignment $ta) => $this->serialize($ta->load('activeSources')), $results),
            201,
        );
    }

    public function breakFromRequirement(Request $request, TrainingAssignment $trainingAssignment): JsonResponse
    {
        Gate::authorize('delete', $trainingAssignment);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'requirement_id' => [
                'required', 'string',
                Rule::exists('requirements', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
        ]);

        $result = $this->service->breakFromRequirement($orgId, $trainingAssignment, $data['requirement_id']);

        return response()->json($result);
    }

    public function destroyByRequirement(Request $request): JsonResponse
    {
        Gate::authorize('deleteAny', TrainingAssignment::class);

        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'user_id' => [
                'required', 'string',
                Rule::exists('users', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
            'requirement_id' => [
                'required', 'string',
                Rule::exists('requirements', 'id')->where('org_id', $orgId)->whereNull('deleted_at'),
            ],
        ]);

        $result = $this->service->removeRequirementSources($orgId, $data['user_id'], $data['requirement_id']);

        return response()->json($result);
    }

    public function destroy(TrainingAssignment $trainingAssignment): JsonResponse
    {
        Gate::authorize('delete', $trainingAssignment);

        $id = $trainingAssignment->id;
        $userId = $trainingAssignment->user_id;
        $trainingId = $trainingAssignment->training_id;
        $orgId = $trainingAssignment->org_id;

        $trainingAssignment->delete();

        event(new TrainingAssignmentDeleted($id, $userId, $trainingId, $orgId));

        return response()->json(['ok' => true]);
    }

    private function serialize(TrainingAssignment $ta): array
    {
        $sources = $ta->relationLoaded('activeSources')
            ? $ta->activeSources
            : $ta->activeSources()->get();

        return [
            'id' => $ta->id,
            'user_id' => $ta->user_id,
            'training_id' => $ta->training_id,
            'name' => $ta->name,
            'expires_at' => $ta->expires_at?->toDateString(),
            'last_completed_at' => $ta->last_completed_at?->toDateString(),
            'active_sources' => $sources->map(fn (AssignmentSource $s) => [
                'id' => $s->id,
                'sourceable_type' => $s->sourceable_type,
                'sourceable_id' => $s->sourceable_id,
                'added_at' => $s->added_at->toISOString(),
            ])->values()->all(),
            'can_delete' => Gate::check('delete', $ta),
        ];
    }
}
