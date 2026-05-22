<?php

namespace App\Http\Controllers;

use App\Events\ClassChanged;
use App\Http\Requests\ClassRequest;
use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Training;
use App\Models\TrainingClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Training System — Phase A: schedule classes + manage their training list
 * and roster. Closing a class out (generating completions) is Phase B.
 *
 * JSON API consumed by the classes Pinia store; the Inertia pages
 * (classes/Index, classes/Show) are thin shells that hydrate from it.
 */
class ClassesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TrainingClass::class);

        $classes = TrainingClass::query()
            ->where('org_id', $request->user()->org_id)
            ->withCount(['classTrainings', 'enrollments'])
            ->orderByDesc('scheduled_date')
            ->get();

        return response()->json($classes->map(fn (TrainingClass $c) => $this->summarize($c)));
    }

    public function show(TrainingClass $class): JsonResponse
    {
        Gate::authorize('view', $class);

        return response()->json($this->detail($class));
    }

    /** Inertia shell for the class detail page; hydrates from the JSON show. */
    public function showPage(TrainingClass $class): Response
    {
        Gate::authorize('view', $class);

        return Inertia::render('classes/Show', ['classId' => $class->id]);
    }

    public function store(ClassRequest $request): JsonResponse
    {
        Gate::authorize('create', TrainingClass::class);

        $class = TrainingClass::create([
            'org_id' => $request->user()->org_id,
            'status' => 'scheduled',
            ...$request->validated(),
        ]);

        event(new ClassChanged($class->id, $class->org_id, 'created'));

        return response()->json($this->detail($class), 201);
    }

    public function update(ClassRequest $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);

        $class->update($request->validated());
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function destroy(TrainingClass $class): JsonResponse
    {
        Gate::authorize('delete', $class);

        $id = $class->id;
        $orgId = $class->org_id;
        $class->delete();

        event(new ClassChanged($id, $orgId, 'deleted'));

        return response()->json(['ok' => true]);
    }

    public function attachTraining(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'training_id' => [
                'required', 'string',
                Rule::exists('trainings', 'id')->where('org_id', $class->org_id)->whereNull('deleted_at'),
            ],
            'hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Snapshot the training's fields so later edits don't rewrite history.
        $training = Training::query()->with('stdFrequency:id,name,repeat_days')->findOrFail($data['training_id']);

        $class->classTrainings()->create([
            'training_id' => $training->id,
            'training_name' => $training->name,
            'initial_only' => $training->initial_only,
            'repeating' => $training->repeating,
            'as_needed' => $training->as_needed,
            'repeat_days' => $training->stdFrequency?->repeat_days,
            'std_freq_name' => $training->stdFrequency?->name,
            'hours' => $data['hours'] ?? null,
        ]);

        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function detachTraining(TrainingClass $class, ClassTraining $classTraining): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_unless($classTraining->class_id === $class->id, 404);

        $classTraining->delete();
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function enroll(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'user_id' => [
                'required', 'string',
                Rule::exists('users', 'id')->where('org_id', $class->org_id)->whereNull('deleted_at'),
                Rule::unique('class_enrollments', 'user_id')->where('class_id', $class->id),
            ],
        ]);

        $class->enrollments()->create(['user_id' => $data['user_id'], 'status' => 'enrolled']);
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function unenroll(TrainingClass $class, ClassEnrollment $enrollment): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_unless($enrollment->class_id === $class->id, 404);

        $enrollment->delete();
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /** @return array<string, mixed> */
    private function summarize(TrainingClass $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'scheduled_date' => $c->scheduled_date?->toDateString(),
            'location' => $c->location,
            'instructor' => $c->instructor,
            'total_hours' => $c->total_hours,
            'status' => $c->status,
            'trainings_count' => $c->class_trainings_count ?? $c->classTrainings()->count(),
            'enrollments_count' => $c->enrollments_count ?? $c->enrollments()->count(),
            'can_edit' => Gate::check('update', $c),
            'can_delete' => Gate::check('delete', $c),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(TrainingClass $c): array
    {
        $c->load([
            'classTrainings',
            'enrollments.user:id,f_name,m_name,l_name,prefix_name,suffix_name',
        ]);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'scheduled_date' => $c->scheduled_date?->toDateString(),
            'location' => $c->location,
            'instructor' => $c->instructor,
            'total_hours' => $c->total_hours,
            'notes' => $c->notes,
            'status' => $c->status,
            'completion_date' => $c->completion_date?->toDateString(),
            'can_edit' => Gate::check('update', $c),
            'trainings' => $c->classTrainings->map(fn (ClassTraining $ct) => [
                'id' => $ct->id,
                'training_id' => $ct->training_id,
                'training_name' => $ct->training_name,
                'initial_only' => $ct->initial_only,
                'repeating' => $ct->repeating,
                'as_needed' => $ct->as_needed,
                'std_freq_name' => $ct->std_freq_name,
                'repeat_days' => $ct->repeat_days,
                'hours' => $ct->hours,
            ])->all(),
            'enrollments' => $c->enrollments->map(fn (ClassEnrollment $e) => [
                'id' => $e->id,
                'user_id' => $e->user_id,
                'user_name' => $e->user?->name,
                'status' => $e->status,
                'notes' => $e->notes,
            ])->all(),
        ];
    }
}
