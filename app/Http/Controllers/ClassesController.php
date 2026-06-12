<?php

namespace App\Http\Controllers;

use App\Events\ClassChanged;
use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Http\Requests\ClassRequest;
use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Training;
use App\Models\TrainingClass;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        DB::transaction(function () use ($class, $enrollment) {
            // De-issue only this person's certs from this class (relevant on a
            // re-opened class; a no-op on one that was never completed).
            Completion::query()
                ->whereIn('class_training_id', $class->classTrainings()->pluck('id'))
                ->where('user_id', $enrollment->user_id)
                ->delete();

            $enrollment->delete();
        });

        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Close out a class: for each enrollee, record a per-training pass/fail
     * result (+ notes), generate a completion for every PASSED enrollee ×
     * training pair (standalone — credited by module identity), roll the
     * enrollee status up to passed / partial / incomplete, set class-level
     * dates, and lock the class to view-only. Idempotent: a completed class
     * can't be re-closed.
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
            'enrollments.*.notes' => ['nullable', 'string'],
            'enrollments.*.results' => ['array'],
            'enrollments.*.results.*.class_training_id' => [
                'required', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
            'enrollments.*.results.*.passed' => ['required', 'boolean'],
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

        // Completion broadcasts are collected inside the transaction and
        // dispatched after commit, so peer tabs never see uncommitted state.
        $issued = [];
        $deIssued = [];

        DB::transaction(function () use ($class, $marks, $completionDate, $expireOverrides, &$issued, &$deIssued) {
            $class->load(['enrollments', 'classTrainings']);
            $totalTopics = $class->classTrainings->count();

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

            // Reconcile completions against the per-topic decisions, per
            // enrollee × training pair. Each topic is tri-state:
            //   passed   + no cert → issue one;  passed + has cert → keep it;
            //   failed   + has cert → de-issue it;
            //   UNMARKED → leave as-is (preserve any existing cert).
            // So a re-close only applies the topics actually marked, and the
            // attendees you didn't touch keep their original certificates. New
            // cert ids continue after the highest suffix used on this date.
            $ctIds = $class->classTrainings->pluck('id');
            $dateStr = $completionDate->format('Ymd');
            $certSeq = $this->maxCertSeqForDate($ctIds, $dateStr);

            foreach ($class->enrollments as $enrollment) {
                $enrollMark = $marks->get($enrollment->id) ?? [];
                $results = collect($enrollMark['results'] ?? [])->pluck('passed', 'class_training_id');

                $passedCount = 0;

                foreach ($class->classTrainings as $ct) {
                    $decision = $results->get($ct->id); // true | false | null (unmarked)
                    $existing = Completion::query()
                        ->where('class_training_id', $ct->id)
                        ->where('user_id', $enrollment->user_id)
                        ->first();

                    if ($decision === false) {
                        if ($existing !== null) {
                            // Explicitly failed → de-issue.
                            $deIssued[] = ['id' => $existing->id, 'user_id' => $existing->user_id];
                            $existing->delete();
                        }

                        continue;
                    }

                    if ($decision === null) {
                        // Unmarked → leave untouched; a preserved cert still counts.
                        if ($existing !== null) {
                            $passedCount++;
                        }

                        continue;
                    }

                    // Passed.
                    $passedCount++;

                    if ($ct->training_id === null || $existing !== null) {
                        continue; // snapshot-only (deleted training), or already credited — preserve.
                    }

                    $certSeq++;
                    $code = $ct->cert_code !== null && $ct->cert_code !== ''
                        ? $ct->cert_code
                        : 'CERT';
                    $certId = sprintf('%s%s-%03d', $code, $dateStr, $certSeq);

                    $issued[] = Completion::create([
                        'org_id' => $class->org_id,
                        'user_id' => $enrollment->user_id,
                        'module_type' => Training::class,
                        'module_id' => $ct->training_id,
                        'completion_date' => $completionDate->toDateString(),
                        'expire_date' => $expiryFor[$ct->id],
                        'cert_id' => $certId,
                        'class_training_id' => $ct->id,
                        'hours' => $ct->hours,
                    ]);
                }

                $enrollment->update([
                    'status' => $this->rollUpStatus($passedCount, $totalTopics),
                    'notes' => $enrollMark['notes'] ?? $enrollment->notes,
                ]);
            }

            $class->update([
                'status' => 'completed',
                'completion_date' => $completionDate->toDateString(),
                'completed_at' => now(),
            ]);
        });

        foreach ($issued as $completion) {
            event(new CompletionCreated($completion->fresh(), actorId: $request->user()->id));
        }

        foreach ($deIssued as $gone) {
            event(new CompletionDeleted($gone['id'], $gone['user_id'], $class->org_id));
        }

        event(new ClassChanged($class->id, $class->org_id, 'completed'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Re-open a completed class for editing — non-destructive: the issued
     * certificates (completions), roster results, and expiries are all left
     * intact; only the lock is released (status back to `scheduled`,
     * `completed_at` cleared, the completion date kept as the default for
     * re-closing). The common case is fixing a typo or adding/removing one
     * person: editing fields touches no certs, removing a person de-issues
     * only theirs (see unenroll), and re-completing reconciles — preserving
     * everyone else's original certificate.
     */
    public function reopen(TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_unless($class->status === 'completed', 422, 'Only a completed class can be re-opened.');

        $class->update([
            'status' => 'scheduled',
            'completed_at' => null,
        ]);

        event(new ClassChanged($class->id, $class->org_id, 'reopened'));

        return response()->json($this->detail($class->fresh()));
    }

    /** Roll an enrollee's per-topic pass count up to a single status. */
    private function rollUpStatus(int $passedCount, int $totalTopics): string
    {
        return match (true) {
            $totalTopics > 0 && $passedCount >= $totalTopics => 'passed',
            $passedCount > 0 => 'partial',
            default => 'incomplete',
        };
    }

    /**
     * Highest `-NNN` suffix already used on this date across the class's certs
     * (incl. soft-deleted), so newly-issued ids on a re-close never collide
     * with preserved or previously-issued ones. cert id = `{code}{YYYYMMDD}-{NNN}`.
     *
     * @param  Collection<int, string>  $ctIds
     */
    private function maxCertSeqForDate(Collection $ctIds, string $dateStr): int
    {
        $max = 0;
        $needle = $dateStr.'-';

        Completion::withTrashed()
            ->whereIn('class_training_id', $ctIds)
            ->whereNotNull('cert_id')
            ->pluck('cert_id')
            ->each(function (string $certId) use (&$max, $needle): void {
                $pos = strpos($certId, $needle);

                if ($pos !== false) {
                    $max = max($max, (int) substr($certId, $pos + strlen($needle)));
                }
            });

        return $max;
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
            'cert_text' => $training->cert_text,
            'lifespan_months' => $training->lifespan_months,
            'cert_code' => $training->cert_code,
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
     * Pre-fill the class's event-level fields (trainer = instructor, venue =
     * location, address) from the training's defaults — but only fields the
     * class hasn't set yet, so the first topic seeds them and later edits or
     * topics never clobber a chosen value.
     */
    private function prefillClassVenue(TrainingClass $class, Training $training): void
    {
        $updates = [];

        if (blank($class->instructor) && filled($training->default_trainer)) {
            $updates['instructor'] = $training->default_trainer;
        }

        if (blank($class->location) && filled($training->default_location)) {
            $updates['location'] = $training->default_location;
        }

        if (blank($class->address) && filled($training->default_address)) {
            $updates['address'] = $training->default_address;
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

        // Per-enrollee credit: which of this class's topics each user already
        // holds a (live) completion for — drives the close-out modal's
        // per-topic defaults so re-completing preserves existing credit, and
        // (M3) the per-training credit lists on the completed-class view.
        $creditRows = Completion::query()
            ->whereIn('class_training_id', $c->classTrainings->pluck('id'))
            ->with('user:id,prefix_name,f_name,m_name,l_name,suffix_name')
            ->get();

        $creditByUser = $creditRows
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('class_training_id')->all());

        $creditsByTopic = $creditRows->groupBy('class_training_id');

        return [
            'id' => $c->id,
            'name' => $c->name,
            'scheduled_date' => $c->scheduled_date?->toDateString(),
            'start_time' => $c->start_time,
            'end_time' => $c->end_time,
            'location' => $c->location,
            'address' => $c->address,
            'instructor' => $c->instructor,
            'show_signature' => $c->show_signature,
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
                // M3 — who earned this topic's credit (completed classes).
                'credits' => ($creditsByTopic[$ct->id] ?? collect())
                    ->map(fn (Completion $comp) => [
                        'completion_id' => $comp->id,
                        'user_id' => $comp->user_id,
                        'user_name' => $comp->user?->name,
                        'cert_id' => $comp->cert_id,
                        'expire_date' => $comp->expire_date?->toDateString(),
                        'hours' => $comp->hours,
                    ])
                    ->sortBy('user_name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ])->all(),
            'enrollments' => $c->enrollments->map(fn (ClassEnrollment $e) => [
                'id' => $e->id,
                'user_id' => $e->user_id,
                'user_name' => $e->user?->name,
                'user_email' => $e->user?->email,
                'status' => $e->status,
                'notes' => $e->notes,
                'credited_training_ids' => $creditByUser->get($e->user_id, []),
            ])->all(),
        ];
    }
}
