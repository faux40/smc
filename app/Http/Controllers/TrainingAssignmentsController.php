<?php

namespace App\Http\Controllers;

use App\Actions\RecalculateTrainingStatus;
use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentDeleted;
use App\Http\Requests\TrainingAssignmentRequest;
use App\Models\AssignmentSource;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class TrainingAssignmentsController extends Controller
{
    public function __construct(
        private RecalculateTrainingStatus $recalculate,
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
            ? $this->assignFromRequirement($orgId, $userId, $data['requirement_id'])
            : [$this->assignDirect($orgId, $userId, $data['training_id'])];

        return response()->json(
            array_map(fn (TrainingAssignment $ta) => $this->serialize($ta->load('activeSources')), $results),
            201,
        );
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Explode a requirement into one TrainingAssignment per training element.
     * Each gets an AssignmentSource pointing at the requirement.
     *
     * @return TrainingAssignment[]
     */
    private function assignFromRequirement(string $orgId, string $userId, string $requirementId): array
    {
        $requirement = Requirement::with(['elements' => fn ($q) => $q->where('module_type', Training::class)])
            ->where('org_id', $orgId)
            ->findOrFail($requirementId);

        $created = [];
        foreach ($requirement->elements as $element) {
            $ta = $this->findOrCreateTrainingAssignment($orgId, $userId, $element->module_id);

            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => Requirement::class,
                'sourceable_id' => $requirement->id,
                'added_at' => now(),
            ]);

            $this->recalculate->handle($userId, $element->module_id);

            event(new TrainingAssignmentCreated($ta->fresh(), actorId: Auth::id()));

            $created[] = $ta->fresh();
        }

        return $created;
    }

    private function assignDirect(string $orgId, string $userId, string $trainingId): TrainingAssignment
    {
        $ta = $this->findOrCreateTrainingAssignment($orgId, $userId, $trainingId);

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        $this->recalculate->handle($userId, $trainingId);

        event(new TrainingAssignmentCreated($ta->fresh(), actorId: Auth::id()));

        return $ta->fresh();
    }

    private function findOrCreateTrainingAssignment(
        string $orgId,
        string $userId,
        string $trainingId,
    ): TrainingAssignment {
        $training = Training::where('org_id', $orgId)->findOrFail($trainingId);

        return TrainingAssignment::firstOrCreate(
            ['user_id' => $userId, 'training_id' => $trainingId],
            ['org_id' => $orgId, 'name' => $training->name],
        );
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
