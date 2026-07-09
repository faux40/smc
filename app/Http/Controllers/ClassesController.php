<?php

namespace App\Http\Controllers;

use App\Actions\CompleteClass;
use App\Events\ClassChanged;
use App\Events\CompletionCreated;
use App\Events\CompletionDeleted;
use App\Events\CompletionUpdated;
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

        $query = TrainingClass::query()
            ->where('org_id', $request->user()->org_id)
            ->withCount(['classTrainings', 'enrollments']);

        // Free-text search (case-insensitive, portable).
        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function ($w) use ($term) {
                $w->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(instructor) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(location) LIKE ?', [$term]);
            });
        }

        // Optional filters — used by the "add to existing class" picker on the
        // compliance training detail (classes that already include a training,
        // still open for enrollment).
        if ($request->filled('training_id')) {
            $query->whereHas('classTrainings', fn ($q) => $q
                ->where('training_id', (string) $request->query('training_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        // Server-side sort. DB columns plus withCount aliases (class_trainings_count,
        // enrollments_count) which are virtual SELECT columns eligible for ORDER BY.
        $sortable = [
            'scheduled_date', 'name', 'total_hours', 'status', 'completion_date', 'created_at',
            'instructor', 'location', 'class_trainings_count', 'enrollments_count',
        ];
        $sort = in_array($request->query('sort'), $sortable, true) ? $request->query('sort') : 'scheduled_date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir)->orderBy('id');

        // Always paginated ({data, meta}); the classes Pinia store is the only
        // consumer and drives it via useServerTable.
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $p = $query->paginate($perPage);

        return response()->json([
            'data' => collect($p->items())->map(fn (TrainingClass $c) => $this->summarize($c)),
            'meta' => [
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
            ],
        ]);
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
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Per-class cert overrides: seeded from the training snapshot at
            // attach time, then editable for this class only. cert_text is
            // Markdown (rendered on the certificate).
            'cert_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cert_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cert_code' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        // Only touch fields the request actually sent, so a cert-only edit
        // doesn't blank hours (and vice-versa).
        $classTraining->update($data);

        if (array_key_exists('hours', $data)) {
            $this->recomputeTotalHours($class);
        }
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
     * Apply a roster diff in one request: enroll the given user-ids and
     * unenroll the given enrollment-ids. Enrolling is idempotent (an
     * already-enrolled user is skipped, not an error) so the picker can send
     * its whole selection without pre-diffing. Unenroll de-issues each
     * dropped user's certs from this class (matters on a re-opened class).
     */
    public function bulkEnrollment(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

        $data = $request->validate([
            'enroll' => ['array'],
            'enroll.*' => [
                'string',
                Rule::exists('users', 'id')->where('org_id', $class->org_id)->whereNull('deleted_at'),
            ],
            'unenroll' => ['array'],
            'unenroll.*' => [
                'string',
                Rule::exists('class_enrollments', 'id')->where('class_id', $class->id),
            ],
            // Explicit intent flag required to wipe an entire multi-person
            // roster (see the guard below).
            'confirm_clear' => ['sometimes', 'boolean'],
        ]);

        $enroll = array_values(array_unique($data['enroll'] ?? []));
        $unenroll = array_values(array_unique($data['unenroll'] ?? []));

        // Safety net against a silent mass de-enroll (the "re-open a class and
        // lose the whole roster" data-loss bug). Removing EVERY member of a
        // multi-person roster with no additions is only honoured when the
        // caller explicitly confirms the intent — an accidental full clear
        // (e.g. a client-side load race) is rejected, not applied. Removing the
        // last person from a one-person roster stays a normal, unguarded edit.
        if ($enroll === [] && count($unenroll) >= 2 && ($data['confirm_clear'] ?? false) !== true) {
            $rosterCount = $class->enrollments()->count();

            abort_if(
                count($unenroll) >= $rosterCount,
                422,
                'Refusing to remove the entire roster without confirmation.',
            );
        }

        DB::transaction(function () use ($class, $enroll, $unenroll) {
            if ($unenroll !== []) {
                $userIds = $class->enrollments()->whereIn('id', $unenroll)->pluck('user_id');

                // De-issue the dropped users' certs from this class's topics.
                Completion::query()
                    ->whereIn('class_training_id', $class->classTrainings()->pluck('id'))
                    ->whereIn('user_id', $userIds)
                    ->delete();

                $class->enrollments()->whereIn('id', $unenroll)->delete();
            }

            foreach ($enroll as $userId) {
                // firstOrCreate keeps this idempotent — no unique-violation on
                // a user who's already on the roster.
                $class->enrollments()->firstOrCreate(
                    ['user_id' => $userId],
                    ['status' => 'enrolled'],
                );
            }
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
    public function complete(Request $request, TrainingClass $class, CompleteClass $action): JsonResponse
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
            'enrollments.*.results.*.result' => ['required', 'in:pass,fail,incomplete'],
            'trainings' => ['array'],
            'trainings.*.id' => [
                'required', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
            'trainings.*.expire_date' => ['nullable', 'date'],
        ]);

        // The reconcile/issue/lock transaction lives in the CompleteClass
        // action (shared with the dev seeders). Completion broadcasts are
        // collected inside the transaction and dispatched after commit, so
        // peer tabs never see uncommitted state.
        ['issued' => $issued, 'deIssued' => $deIssued] = $action->handle(
            $class,
            CarbonImmutable::parse($data['completion_date']),
            collect($data['enrollments'] ?? [])->keyBy('id'),
            collect($data['trainings'] ?? [])->pluck('expire_date', 'id'),
        );

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
     * certificates (completions AND their numbers), roster results, and
     * expiries are all left intact; only the lock is released (status back to
     * `scheduled`, `completed_at` cleared, the completion date kept as the
     * default for re-closing). Because re-close only re-mints NULL cert_ids and
     * preserves present ones, a re-open → fix-a-typo → re-close round-trip
     * leaves every existing certificate number byte-for-byte identical. Editing
     * fields touches no certs, removing a person de-issues only theirs (see
     * unenroll), and re-completing reconciles — preserving everyone else's
     * original certificate. Deliberate renumbering is a separate, explicit
     * action (see reissueCertificates).
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

    /**
     * Re-lock a re-opened class WITHOUT re-running the reconciliation — the
     * lightweight counterpart to `complete()`. For a class that was fixed up
     * in edit mode (typo, single-cert revoke/issue — all of which already
     * apply immediately) there's nothing left to reconcile, so this just
     * flips the status back to `completed` and stamps `completed_at`. It
     * deliberately does NOT call the `CompleteClass` action: no completions
     * are issued or de-issued, no results are read, no expiries are
     * re-stamped. Only valid on a class that's `scheduled` AND was
     * previously completed (`completion_date` set) — i.e. re-opened, not a
     * fresh scheduled class.
     */
    public function reclose(TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_if($class->status === 'completed', 422, 'This class is already completed.');
        abort_if($class->completion_date === null, 422, "This class hasn't been completed yet — use Complete.");

        $class->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        event(new ClassChanged($class->id, $class->org_id, 'completed'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Deliberately renumber issued certificates for a re-opened (scheduled)
     * class — the whole class, or a single topic when `class_training_id` is
     * given. NULLs the affected completions' cert_ids so the NEXT re-close
     * re-mints them from the current cert_code/date via the shared close-out
     * path (one numbering code path — nothing is minted here). A no-op when
     * there are no issued certs to clear (e.g. a never-completed class).
     * Previously printed certificates will no longer match after re-close.
     */
    public function reissueCertificates(Request $request, TrainingClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

        $data = $request->validate([
            'class_training_id' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
        ]);

        $ctIds = $class->classTrainings()->pluck('id');

        if (($data['class_training_id'] ?? null) !== null) {
            $ctIds = $ctIds->filter(fn ($id) => $id === $data['class_training_id'])->values();
        }

        $cleared = Completion::query()
            ->whereIn('class_training_id', $ctIds)
            ->whereNotNull('cert_id')
            ->get();

        DB::transaction(function () use ($cleared) {
            Completion::whereKey($cleared->pluck('id'))->update(['cert_id' => null]);
        });

        foreach ($cleared as $completion) {
            event(new CompletionUpdated($completion->fresh()));
        }

        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Revoke a single issued certificate on a re-opened (scheduled) class.
     * Soft-deletes the completion (retaining `revoke_reason` + `deleted_at` for
     * audit) AND sets the owning enrollment's result for that topic to
     * `incomplete`, so the authoritative re-close reconcile won't resurrect it.
     * The CompletionObserver's `deleted` hook recalculates the user's training
     * status. Returns the refreshed detail.
     */
    public function revokeCompletion(Request $request, TrainingClass $class, Completion $completion): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

        // The completion must be org-scoped and belong to one of THIS class's
        // topics (route binding already rejects a cross-org id).
        $ctIds = $class->classTrainings()->pluck('id');
        abort_unless(
            $completion->org_id === $class->org_id && $ctIds->contains($completion->class_training_id),
            404,
        );

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($class, $completion, $data) {
            // Retain the reason on the soft-deleted row for auditors, then pull
            // the cert.
            $completion->update(['revoke_reason' => $data['reason'] ?? null]);
            $completion->delete();

            // Keep the results map in step so re-close treats this topic as
            // non-pass for this enrollee and leaves the cert revoked.
            $enrollment = $class->enrollments()->where('user_id', $completion->user_id)->first();
            if ($enrollment !== null) {
                $results = $enrollment->results ?? [];
                $results[$completion->class_training_id] = 'incomplete';
                $enrollment->update(['results' => $results]);
                $this->rollUpEnrollmentStatus($enrollment, $class);
            }
        });

        event(new CompletionDeleted($completion->id, $completion->user_id, $class->org_id));
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Issue a single certificate to a (possibly un-rostered) person on a
     * re-opened (scheduled) class — the "missed someone" path. Enrolls the user
     * if needed, mints the next cert number in the class's per-date sequence via
     * the shared close-out numbering (CompleteClass::nextCertId — one code
     * path), and sets the enrollment's result for that topic to `pass` so
     * re-close preserves the credit and its number. Guarded against duplicating
     * a live cert. Returns the refreshed detail.
     */
    public function issueCompletion(Request $request, TrainingClass $class, CompleteClass $action): JsonResponse
    {
        Gate::authorize('update', $class);
        $this->assertEditable($class);

        $data = $request->validate([
            'user_id' => [
                'required', 'string',
                Rule::exists('users', 'id')->where('org_id', $class->org_id)->whereNull('deleted_at'),
            ],
            'class_training_id' => [
                'required', 'string',
                Rule::exists('class_training', 'id')->where('class_id', $class->id),
            ],
        ]);

        $ct = $class->classTrainings()->findOrFail($data['class_training_id']);

        // A snapshot-only topic (its training was deleted) can't be credited fresh.
        abort_if($ct->training_id === null, 422, 'This topic no longer references a training and cannot issue a certificate.');

        // Don't duplicate a live cert for this (user, topic).
        abort_if(
            Completion::query()
                ->where('class_training_id', $ct->id)
                ->where('user_id', $data['user_id'])
                ->exists(),
            422,
            'This person already has a certificate for this topic.',
        );

        // Anchor the number/date to the class's completion date (kept across a
        // re-open); fall back to the scheduled date for a never-completed class.
        $completionDate = CarbonImmutable::parse(
            $class->completion_date ?? $class->scheduled_date ?? now(),
        );

        $completion = DB::transaction(function () use ($class, $ct, $data, $action, $completionDate) {
            // Enroll if not already on the roster (idempotent, like bulkEnrollment).
            $enrollment = $class->enrollments()->firstOrCreate(
                ['user_id' => $data['user_id']],
                ['status' => 'enrolled'],
            );

            $completion = Completion::create([
                'org_id' => $class->org_id,
                'user_id' => $data['user_id'],
                'module_type' => Training::class,
                'module_id' => $ct->training_id,
                'completion_date' => $completionDate->toDateString(),
                'expire_date' => $ct->expire_date,
                'cert_id' => $action->nextCertId($class, $ct, $completionDate),
                'class_training_id' => $ct->id,
                'hours' => $ct->hours,
            ]);

            // Keep the results map in step so re-close preserves this credit.
            $results = $enrollment->results ?? [];
            $results[$ct->id] = 'pass';
            $enrollment->update(['results' => $results]);
            $this->rollUpEnrollmentStatus($enrollment, $class);

            return $completion;
        });

        event(new CompletionCreated($completion->fresh(), actorId: $request->user()->id));
        event(new ClassChanged($class->id, $class->org_id, 'updated'));

        return response()->json($this->detail($class->fresh()));
    }

    /**
     * Re-derive an enrollment's roster status from its results map using the
     * same rule close-out applies, so the roster badge stays truthful after a
     * single revoke/issue (which only touch one topic).
     */
    private function rollUpEnrollmentStatus(ClassEnrollment $enrollment, TrainingClass $class): void
    {
        $passed = collect($enrollment->results ?? [])->filter(fn ($r) => $r === 'pass')->count();

        $enrollment->update([
            'status' => CompleteClass::rollUpStatus($passed, $class->classTrainings()->count()),
        ]);
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
                // Per-class cert overrides (editable while scheduled).
                'cert_title' => $ct->cert_title,
                'cert_text' => $ct->cert_text,
                'cert_code' => $ct->cert_code,
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
                // Per-topic pass/fail/incomplete map (pre-fills the complete
                // modal on re-close; drives the roster's three-state display).
                'results' => $e->results ?? [],
            ])->all(),
        ];
    }
}
