<?php

namespace Database\Seeders;

use App\Actions\CompleteClass;
use App\Actions\RecalculateTrainingStatus;
use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\TrainingClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only scenario seeder (Phase N). Layers on top of DevDataSeeder and
 * gives the BG org the demo cases the generic fan-out can't guarantee:
 *
 *   N1 — Classes: 2 scheduled future classes + 3 completed ones with mixed
 *        pass/partial/incomplete rosters and hours set. Close-out goes
 *        through the CompleteClass action — the exact path the controller
 *        uses — so cert ids, per-topic expiries, and the observer → recalc
 *        chain all behave like a real close-out.
 *   N2 — Multi-source timing: one named user holding the same training
 *        direct + via two requirements whose elements carry different
 *        frequencies (exercises J2 strictest-wins), and one named user
 *        with retroactive credit (completion predating the assignment).
 *   N3 — The new requirements/users get multi-tag coverage like the rest
 *        of the org; together with DevDataSeeder's spread (and the 10-day
 *        First Aid class below) every dashboard bucket stays populated.
 *
 * Idempotent (sentinel: the "Spring Safety Stand-Down" class in BG).
 * Gated to the `local` env from DatabaseSeeder.
 */
class DevScenarioSeeder extends Seeder
{
    private const ORG_NAME = 'BG';

    private const SENTINEL_CLASS = 'Spring Safety Stand-Down';

    /** N2 user who holds Fall Protection direct + via two requirements. */
    private const MULTI_SOURCE_USER = ['f_name' => 'Victor', 'l_name' => 'Reyes'];

    /** N2 user whose completion predates their assignment. */
    private const RETRO_CREDIT_USER = ['f_name' => 'Priya', 'l_name' => 'Natarajan'];

    public function __construct(
        private CompleteClass $completeClass,
        private RecalculateTrainingStatus $recalculate,
    ) {}

    public function run(): void
    {
        $org = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('name', self::ORG_NAME)
            ->first();

        if ($org === null) {
            return; // DevSeeder must run first.
        }

        $trainings = Training::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->with('stdFrequency')
            ->get()
            ->keyBy('name');

        if (! $trainings->has('Fall Protection')) {
            return; // DevDataSeeder must run first.
        }

        $alreadySeeded = TrainingClass::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('name', self::SENTINEL_CLASS)
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        // The roster pool: everyone but the org owner, in a stable order so
        // class sizes are deterministic even though names rotate with faker.
        $students = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereKeyNot($org->owner_user_id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();

        DB::transaction(function () use ($org, $trainings, $students): void {
            $this->seedScheduledClasses($org, $trainings, $students);
            $this->seedCompletedClasses($org, $trainings, $students);
            $this->seedMultiSourceTiming($org, $trainings);
            $this->seedRetroactiveCredit($org, $trainings);
        });
    }

    /**
     * N1 — two future classes, still open for roster edits.
     *
     * @param  Collection<string, Training>  $trainings  keyed by name
     * @param  Collection<int, User>  $students
     */
    private function seedScheduledClasses(
        Organization $org,
        Collection $trainings,
        Collection $students,
    ): void {
        $this->buildClass($org, [
            'name' => 'Fall Protection Refresher',
            'scheduled_date' => now()->addDays(21)->toDateString(),
            'location' => 'Site A — Training Trailer',
            'instructor' => 'Dale Hutchins',
        ], [
            ['training' => $trainings['Fall Protection']],
        ], $students->slice(0, 5));

        $this->buildClass($org, [
            'name' => 'Equipment Operator Day',
            'scheduled_date' => now()->addDays(45)->toDateString(),
            'location' => 'Main Yard',
            'instructor' => 'Carmen Ortiz',
        ], [
            ['training' => $trainings['Forklift']],
            ['training' => $trainings['Hazmat']],
        ], $students->slice(5, 4));
    }

    /**
     * N1 — three completed classes with mixed rosters, closed out through
     * the CompleteClass action so completions/certs/expiries are real.
     *
     * @param  Collection<string, Training>  $trainings  keyed by name
     * @param  Collection<int, User>  $students
     */
    private function seedCompletedClasses(
        Organization $org,
        Collection $trainings,
        Collection $students,
    ): void {
        // Two topics, five students: 2 pass both / 2 pass one / 1 fails both
        // → passed + partial + incomplete all appear on one roster.
        $standDown = $this->buildClass($org, [
            'name' => self::SENTINEL_CLASS,
            'scheduled_date' => now()->subDays(45)->toDateString(),
            'location' => 'Site B — Assembly Area',
            'instructor' => 'Dale Hutchins',
        ], [
            ['training' => $trainings['Fall Protection']],
            ['training' => $trainings['Lockout/Tagout']],
        ], $students->slice(0, 5));

        $this->closeOut($standDown, now()->subDays(45), [
            [true, true], [true, true], [true, false], [false, true], [false, false],
        ]);

        // Single 10-day-repeat topic completed 6 days ago → the issued certs
        // expire in ~4 days, keeping the Due-soon bucket populated (N3).
        $skillsDay = $this->buildClass($org, [
            'name' => 'First Aid & CPR Skills Day',
            'scheduled_date' => now()->subDays(6)->toDateString(),
            'location' => 'Main Office — Conference Room',
            'instructor' => 'Carmen Ortiz',
        ], [
            ['training' => $trainings['First Aid']],
        ], $students->slice(5, 3));

        $this->closeOut($skillsDay, now()->subDays(6), [
            [true], [true], [true],
        ]);

        // Initial-only + as-needed topics → completions with no expiry; the
        // as-needed topic has no default hours, so the class sets them (N1
        // wants hours on every topic).
        $confinedSpace = $this->buildClass($org, [
            'name' => 'Confined Space Entry Workshop',
            'scheduled_date' => now()->subDays(120)->toDateString(),
            'location' => 'Site A — Tank Farm',
            'instructor' => 'Ray Delgado',
        ], [
            ['training' => $trainings['Confined Space']],
            ['training' => $trainings['Hearing Conservation'], 'hours' => 1.0],
        ], $students->slice(8, 3));

        $this->closeOut($confinedSpace, now()->subDays(120), [
            [true, true], [true, true], [true, false],
        ]);
    }

    /**
     * N2 — Victor Reyes holds Fall Protection three ways at once: direct
     * (template timing, Annual/365d) and via two new requirements whose
     * elements override the frequency (Semi-Annual/180d and Quarterly/90d).
     * His completion carries no explicit expiry, so RecalculateTrainingStatus
     * must derive it — and the strictest source (90d) has to win.
     *
     * @param  Collection<string, Training>  $trainings  keyed by name
     */
    private function seedMultiSourceTiming(Organization $org, Collection $trainings): void
    {
        $fallProtection = $trainings['Fall Protection'];
        $freqs = StdFrequency::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->get()
            ->keyBy('name');

        $victor = User::factory()
            ->forOrganization($org)
            ->noLogin()
            ->withRole('SelfView')
            ->create([
                'f_name' => self::MULTI_SOURCE_USER['f_name'],
                'l_name' => self::MULTI_SOURCE_USER['l_name'],
                'job_title' => 'Tower Technician',
            ]);

        $requirements = collect([
            ['name' => 'Roofing Crew', 'description' => 'Steep-slope roofing work above 6 ft.', 'freq' => 'Semi-Annual'],
            ['name' => 'Tower Rescue', 'description' => 'Elevated rescue standby duty.', 'freq' => 'Quarterly'],
        ])->map(function (array $row) use ($org, $fallProtection, $freqs) {
            $req = Requirement::create([
                'org_id' => $org->id,
                'name' => $row['name'],
                'description' => $row['description'],
            ]);

            // Same training as the template, stricter per-case frequency.
            RqmtElement::create([
                'org_id' => $org->id,
                'requirement_id' => $req->id,
                'module_type' => Training::class,
                'module_id' => $fallProtection->id,
                'name' => $fallProtection->name,
                'description' => $fallProtection->description,
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freqs[$row['freq']]->id,
                'as_needed' => false,
            ]);

            return $req;
        });

        $ta = TrainingAssignment::create([
            'org_id' => $org->id,
            'user_id' => $victor->id,
            'training_id' => $fallProtection->id,
            'name' => $fallProtection->name,
        ]);

        // Direct source (null sourceable) + one source per requirement.
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        foreach ($requirements as $req) {
            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => Requirement::class,
                'sourceable_id' => $req->id,
                'added_at' => now(),
            ]);
        }

        // No expire_date: the observer-driven recalc derives it from the
        // sources, so expires_at lands at completion + 90d (Quarterly wins
        // over Semi-Annual and the Annual template).
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $victor->id,
            'module_type' => Training::class,
            'module_id' => $fallProtection->id,
            'completion_date' => now()->subDays(30)->toDateString(),
        ]);

        $this->tagModels($org, $requirements->push($victor));
    }

    /**
     * N2 — Priya Natarajan completed Lockout/Tagout 60 days before anyone
     * assigned it to her. The completion exists first; creating the
     * assignment afterwards must pick the credit up retroactively.
     *
     * @param  Collection<string, Training>  $trainings  keyed by name
     */
    private function seedRetroactiveCredit(Organization $org, Collection $trainings): void
    {
        $loto = $trainings['Lockout/Tagout'];

        $priya = User::factory()
            ->forOrganization($org)
            ->noLogin()
            ->withRole('SelfView')
            ->create([
                'f_name' => self::RETRO_CREDIT_USER['f_name'],
                'l_name' => self::RETRO_CREDIT_USER['l_name'],
                'job_title' => 'Maintenance Electrician',
            ]);

        // Completion first — no TA exists yet, so the observer's recalc is a
        // no-op here.
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $priya->id,
            'module_type' => Training::class,
            'module_id' => $loto->id,
            'completion_date' => now()->subDays(60)->toDateString(),
        ]);

        $ta = TrainingAssignment::create([
            'org_id' => $org->id,
            'user_id' => $priya->id,
            'training_id' => $loto->id,
            'name' => $loto->name,
        ]);

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);

        // Same recalc every source-add triggers in the controllers.
        $this->recalculate->handle($priya->id, $loto->id);

        $this->tagModels($org, collect([$priya]));
    }

    /**
     * Build a scheduled class: snapshot each training onto it the way
     * ClassesController::snapshotTraining does, enroll the given users, and
     * sum total hours.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{training: Training, hours?: float}>  $topics
     * @param  Collection<int, User>  $roster
     */
    private function buildClass(
        Organization $org,
        array $attributes,
        array $topics,
        Collection $roster,
    ): TrainingClass {
        $class = TrainingClass::create($attributes + [
            'org_id' => $org->id,
            'status' => 'scheduled',
            'show_signature' => true,
        ]);

        foreach ($topics as $topic) {
            $training = $topic['training'];
            $class->classTrainings()->create([
                'training_id' => $training->id,
                'training_name' => $training->name,
                'initial_only' => $training->initial_only,
                'repeating' => $training->repeating,
                'as_needed' => $training->as_needed,
                'repeat_days' => $training->stdFrequency?->repeat_days,
                'std_freq_name' => $training->stdFrequency?->name,
                'hours' => $topic['hours'] ?? $training->default_hours,
                'cert_title' => $training->cert_title,
                'cert_text' => $training->cert_text,
                'lifespan_months' => $training->lifespan_months,
                'cert_code' => $training->cert_code,
            ]);
        }

        $class->update(['total_hours' => (float) $class->classTrainings()->sum('hours')]);

        foreach ($roster as $user) {
            $class->enrollments()->create([
                'user_id' => $user->id,
                'status' => 'enrolled',
            ]);
        }

        return $class;
    }

    /**
     * Close a class out through the shared CompleteClass action. $results is
     * one row per enrollee (roster order), one bool per topic (topic order).
     *
     * @param  array<int, array<int, bool>>  $results
     */
    private function closeOut(TrainingClass $class, \DateTimeInterface $completionDate, array $results): void
    {
        $class->load(['enrollments', 'classTrainings']);
        $ctIds = $class->classTrainings->pluck('id')->values();

        $marks = $class->enrollments->values()
            ->map(fn ($enrollment, int $i) => [
                'id' => $enrollment->id,
                'results' => $ctIds
                    ->map(fn (string $ctId, int $t) => [
                        'class_training_id' => $ctId,
                        'passed' => $results[$i][$t] ?? false,
                    ])
                    ->all(),
            ])
            ->keyBy('id');

        $this->completeClass->handle(
            $class,
            CarbonImmutable::parse($completionDate),
            $marks,
            collect(),
        );
    }

    /**
     * N3 — keep the new entities multi-tagged like the rest of the org.
     *
     * @param  Collection<int, Model>  $models
     */
    private function tagModels(Organization $org, Collection $models): void
    {
        $tagIds = Tag::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->pluck('id');

        if ($tagIds->isEmpty()) {
            return;
        }

        foreach ($models as $model) {
            $model->tags()->syncWithoutDetaching(
                $tagIds->shuffle()->take(min(2, $tagIds->count()))->all(),
            );
        }
    }
}
