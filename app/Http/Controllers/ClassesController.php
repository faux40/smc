<?php

namespace App\Http\Controllers;

use App\Events\ClassChanged;
use App\Http\Requests\ClassRequest;
use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $data = $request->validated();
        $trainingIds = $data['training_ids'] ?? [];
        unset($data['training_ids']);

        $class = DB::transaction(function () use ($request, $data, $trainingIds) {
            $class = TrainingClass::create([
                'org_id' => $request->user()->org_id,
                'status' => 'scheduled',
                ...$data,
            ]);

            if ($trainingIds !== []) {
                $trainings = Training::query()
                    ->with('stdFrequency:id,name,repeat_days')
                    ->whereIn('id', $trainingIds)
                    ->get();

                foreach ($trainings as $training) {
                    $this->snapshotTraining($class, $training, null);
                }

                $this->recomputeTotalHours($class);
            }

            return $class;
        });

        event(new ClassChanged($class->id, $class->org_id, 'created'));

        return response()->json($this->detail($class), 201);
    }

    public function update(ClassRequest $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

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
        $this->assertEditable($class);

        $data = $request->validate([
            'training_id' => [
                'required', 'string',
                Rule::exists('trainings', 'id')->where('org_id', $class->org_id)->whereNull('deleted_at'),
            ],
            'hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $training = Training::query()->with('stdFrequency:id,name,repeat_days')->findOrFail($data['training_id']);
        $this->snapshotTraining($class, $training, $data['hours'] ?? null);
        $this->recomputeTotalHours($class);

        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function updateTraining(Request $request, TrainingClass $class, ClassTraining $classTraining): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);
        abort_unless($classTraining->class_id === $class->id, 404);

        $data = $request->validate([
            'hours' => ['nullable', 'numeric', 'min:0'],
        ]);

        $classTraining->update(['hours' => $data['hours'] ?? null]);
        $this->recomputeTotalHours($class);
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function detachTraining(TrainingClass $class, ClassTraining $classTraining): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);
        abort_unless($classTraining->class_id === $class->id, 404);

        $classTraining->delete();
        $this->recomputeTotalHours($class);
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    public function enroll(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

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
        $this->assertEditable($class);
        abort_unless($enrollment->class_id === $class->id, 404);

        $enrollment->delete();
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Close out a class: mark each enrollee passed/incomplete (+ notes), then
     * generate a completion for every PASSED enrollee × associated training
     * (standalone — credited by module identity), set class-level dates, and
     * lock the class to view-only. Idempotent: a completed class can't be
     * re-closed.
     */
    public function complete(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_if($class->status === 'completed', 422, 'This class is already completed.');

        $data = $request->validate([
            'completion_date' => ['required', 'date'],
            'enrollments' => ['array'],
            'enrollments.*.id' => [
                'required', 'string',
                Rule::exists('class_enrollments', 'id')->where('class_id', $class->id),
            ],
            'enrollments.*.status' => ['required', Rule::in(['passed', 'incomplete'])],
            'enrollments.*.notes' => ['nullable', 'string'],
            'trainings' => ['array'],
            'trainings.*.id' => [
                'required', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
            'trainings.*.expire_date' => ['nullable', 'date'],
        ]);

        $completionDate = CarbonImmutable::parse($data['completion_date']);
        $expireOverrides = collect($data['trainings'] ?? [])->pluck('expire_date', 'id');
        $marks = collect($data['enrollments'] ?? [])->keyBy('id');

        DB::transaction(function () use ($class, $marks, $completionDate, $expireOverrides) {
            $class->load(['enrollments', 'classTrainings']);

            foreach ($class->enrollments as $enrollment) {
                $mark = $marks->get($enrollment->id);

                if ($mark) {
                    $enrollment->update([
                        'status' => $mark['status'],
                        'notes' => $mark['notes'] ?? $enrollment->notes,
                    ]);
                }
            }

            // Per-training expiry: instructor override, else computed from the
            // snapshot freq (class date + repeat_days; none for initial/as-needed).
            $expiryFor = [];

            foreach ($class->classTrainings as $ct) {
                $expire = $expireOverrides->has($ct->id)
                    ? $expireOverrides->get($ct->id)
                    : ($ct->repeating && $ct->repeat_days
                        ? $completionDate->addDays($ct->repeat_days)->toDateString()
                        : null);
                $ct->update(['expire_date' => $expire]);
                $expiryFor[$ct->id] = $expire;
            }

            // Completions for passed enrollees × associated trainings, each
            // with a sequential-per-class certificate id
            // (`{cert_code}{YYYYMMDD}-{NNN}`) and a link back to the snapshot.
            $certSeq = 0;

            foreach ($class->enrollments->where('status', 'passed') as $enrollment) {
                foreach ($class->classTrainings as $ct) {
                    if ($ct->training_id === null) {
                        continue; // snapshot-only (training deleted) — nothing to credit.
                    }

                    $certSeq++;
                    $code = $ct->cert_code !== null && $ct->cert_code !== ''
                        ? $ct->cert_code
                        : 'CERT';
                    $certId = sprintf(
                        '%s%s-%03d',
                        $code,
                        $completionDate->format('Ymd'),
                        $certSeq,
                    );

                    Completion::create([
                        'org_id' => $class->org_id,
                        'user_id' => $enrollment->user_id,
                        'module_type' => Training::class,
                        'module_id' => $ct->training_id,
                        'completion_date' => $completionDate->toDateString(),
                        'expire_date' => $expiryFor[$ct->id],
                        'cert_id' => $certId,
                        'class_training_id' => $ct->id,
                    ]);
                }
            }

            $class->update([
                'status' => 'completed',
                'completion_date' => $completionDate->toDateString(),
                'completed_at' => now(),
            ]);
        });

        event(new ClassChanged($class->id, $class->org_id, 'completed'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Snapshot a training's template fields onto the class so later edits to
     * the training don't rewrite class history. Shared by store() (the at-
     * create picker) and attachTraining() (the detail-page picker).
     */
    private function snapshotTraining(TrainingClass $class, Training $training, ?float $hours): void
    {
        $class->classTrainings()->create([
            'training_id' => $training->id,
            'training_name' => $training->name,
            'initial_only' => $training->initial_only,
            'repeating' => $training->repeating,
            'as_needed' => $training->as_needed,
            'repeat_days' => $training->stdFrequency?->repeat_days,
            'std_freq_name' => $training->stdFrequency?->name,
            // Default the per-class hours to the topic's typical duration;
            // editable per class via updateTraining().
            'hours' => $hours ?? $training->default_hours,
            // Snapshot the cert content so later edits to the training don't
            // rewrite certs an already-completed class issued.
            'cert_title' => $training->cert_title,
            'cert_text_line_1' => $training->cert_text_line_1,
            'cert_text_line_2' => $training->cert_text_line_2,
            'cert_text_line_3' => $training->cert_text_line_3,
            'cert_text_line_4' => $training->cert_text_line_4,
            'lifespan_months' => $training->lifespan_months,
            'cert_code' => $training->cert_code,
            'show_signature_on_cert' => $training->show_signature_on_cert,
        ]);

        $this->prefillClassVenue($class, $training);
    }

    /** Class total hours = the sum of its topics' (adjusted) hours. */
    private function recomputeTotalHours(TrainingClass $class): void
    {
        $class->update([
            'total_hours' => (float) $class->classTrainings()->sum('hours'),
        ]);
    }

    /**
     * Pre-fill the class's event-level fields (trainer = instructor, training
     * location + address) from the training's defaults — but only fields the
     * class hasn't set yet, so the first topic seeds them and later edits or
     * topics never clobber a chosen value.
     */
    private function prefillClassVenue(TrainingClass $class, Training $training): void
    {
        $updates = [];

        if (blank($class->instructor) && filled($training->default_trainer)) {
            $updates['instructor'] = $training->default_trainer;
        }

        if (blank($class->training_location) && filled($training->default_training_location)) {
            $updates['training_location'] = $training->default_training_location;
        }

        if (blank($class->training_address) && filled($training->default_training_address)) {
            $updates['training_address'] = $training->default_training_address;
        }

        if ($updates !== []) {
            $class->update($updates);
        }
    }

    /** A completed class is view-only — block roster/training/detail edits. */
    private function assertEditable(TrainingClass $class): void
    {
        abort_if($class->status === 'completed', 422, 'This class is completed and read-only.');
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
            'enrollments.user:id,f_name,m_name,l_name,prefix_name,suffix_name,email',
        ]);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'scheduled_date' => $c->scheduled_date?->toDateString(),
            'location' => $c->location,
            'training_location' => $c->training_location,
            'training_address' => $c->training_address,
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
                'user_email' => $e->user?->email,
                'status' => $e->status,
                'notes' => $e->notes,
            ])->all(),
        ];
    }
}
