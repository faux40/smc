<?php

namespace App\Console\Commands;

use App\Actions\BackfillClassEnrollments;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One-off importer: pulls a single TrainingWise org (people, courses,
 * classes, certification history) into the new SMC app. Reads the legacy
 * data via the read-only `trainingwise` connection (a throwaway MariaDB
 * container loaded from a dump); writes the new rows on the default pgsql
 * connection inside a transaction.
 *
 *   php artisan tw:migrate 12 --dry-run        # rehearse, roll back
 *   php artisan tw:migrate 12                   # commit
 *   php artisan tw:migrate 12 --limit=50        # quick smoke (cap people/certs)
 *
 * Idempotent via `legacy_tw_map`: a committed re-run reuses the rows it
 * already created instead of duplicating. Class ATTACHMENTS are a separate
 * phase (tw:migrate-attachments) since they need AWS credentials.
 */
class MigrateTrainingWise extends Command
{
    protected $signature = 'tw:migrate {twOrgId : TrainingWise organization id (e.g. 12 or 15)}
        {--dry-run : Roll everything back at the end (rehearsal)}
        {--limit=0 : Cap employees + certifications for a fast smoke test (0 = no cap)}';

    protected $description = 'Import a TrainingWise org (people + training history) into SMC';

    private Connection $tw;

    private string $orgId;

    private int $twOrgId;

    /** lowercased emails already taken (preloaded + claimed this run) */
    private array $usedEmails = [];

    /** tw course id => ['uuid','name','initial_only','repeating','as_needed','repeat_days','std_freq_name','hours','cert_title','cert_text'] */
    private array $courses = [];

    /** tw class id => ['class_uuid','ct_uuid','course_id','start_date'] */
    private array $classes = [];

    private const DAYS_PER_UNIT = [1 => 1, 2 => 7, 3 => 30, 4 => 365, 5 => 0]; // Days/Weeks/Months/Years/Never

    public function handle(): int
    {
        $this->tw = DB::connection('trainingwise');
        $this->twOrgId = (int) $this->argument('twOrgId');
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $twOrg = $this->tw->table('organizations')->where('id', $this->twOrgId)->first();
        if (! $twOrg) {
            $this->error("TrainingWise org {$this->twOrgId} not found on the trainingwise connection.");

            return self::FAILURE;
        }

        $this->info("Importing TW org {$this->twOrgId} — {$twOrg->name}".($dryRun ? '  [DRY RUN]' : '').($limit ? "  [limit {$limit}]" : ''));

        $this->usedEmails = User::query()->whereNotNull('email')
            ->pluck('email')->map(fn ($e) => strtolower($e))->flip()->all();

        try {
            DB::transaction(function () use ($twOrg, $limit, $dryRun) {
                Model::unguarded(function () use ($twOrg, $limit, $dryRun) {
                    $this->importOrg($twOrg);
                    $this->importStdFrequenciesAndCourses();
                    $this->importEmployees($limit);
                    $this->importClasses();
                    $this->importCertifications($limit);
                    // TW has no roster table — reconstruct enrollments from the
                    // certs we just imported so classes show who attended.
                    $enrolled = app(BackfillClassEnrollments::class)->handle($this->orgId);
                    $this->info("  enrollments backfilled from completions: {$enrolled}");

                    if ($dryRun) {
                        throw new DryRunComplete;
                    }
                });
            });
        } catch (DryRunComplete) {
            $this->warn('DRY RUN complete — all changes rolled back.');

            return self::SUCCESS;
        }

        $this->info('Import committed.');

        return self::SUCCESS;
    }

    // ---- entities ---------------------------------------------------------

    private function importOrg(object $twOrg): void
    {
        if ($existing = $this->mapGet('org', $this->twOrgId)) {
            $this->orgId = $existing;
            Organization::whereKey($existing)->update(['name' => $twOrg->name]);
            $this->line("  org: reuse {$existing}");

            return;
        }

        $org = Organization::create(['name' => $twOrg->name, 'timezone' => 'America/New_York']);
        $this->orgId = $org->id;
        $this->mapPut('org', $this->twOrgId, $org->id);
        $this->line("  org: created {$org->id}");
    }

    private function importStdFrequenciesAndCourses(): void
    {
        // Per-org std_frequencies, derived from the distinct repeat windows
        // of the org's repeating courses (repeat_id=1).
        $courses = $this->tw->table('courses')->where('organization_id', $this->twOrgId)->get();

        $freqByDays = [];   // repeat_days => StdFrequency uuid
        foreach ($courses as $c) {
            if ((int) $c->repeat_id !== 1) {
                continue;
            }
            $days = (int) $c->lifespan * (self::DAYS_PER_UNIT[(int) $c->lifespanunit_id] ?? 0);
            if ($days > 0 && ! isset($freqByDays[$days])) {
                $freqByDays[$days] = $this->ensureStdFrequency($days);
            }
        }

        foreach ($courses as $c) {
            $this->importCourse($c, $freqByDays);
        }
        $this->line('  trainings: '.count($this->courses).'  (std_frequencies: '.count($freqByDays).')');
    }

    private function ensureStdFrequency(int $days): string
    {
        $name = $this->freqName($days);
        $freq = StdFrequency::create(['org_id' => $this->orgId, 'name' => $name, 'repeat_days' => $days]);

        return $freq->id;
    }

    private function importCourse(object $c, array $freqByDays): void
    {
        $repeatId = (int) $c->repeat_id;
        $days = (int) $c->lifespan * (self::DAYS_PER_UNIT[(int) $c->lifespanunit_id] ?? 0);

        $repeating = $repeatId === 1 && $days > 0;
        $initialOnly = $repeatId === 2;
        // repeat_id 3 (As Needed) — and any repeating course that resolves to
        // a 0-day window — falls through to as_needed.
        $asNeeded = ! $repeating && ! $initialOnly;

        $stdFreqId = $repeating ? ($freqByDays[$days] ?? null) : null;

        $certText = implode("\n", array_filter([
            $c->cert_text_line_1 ?? null, $c->cert_text_line_2 ?? null,
            $c->cert_text_line_3 ?? null, $c->cert_text_line_4 ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== ''));

        $hours = $c->duration_hours !== null && (float) $c->duration_hours > 0 ? (float) $c->duration_hours : null;

        if ($uuid = $this->mapGet('training', (int) $c->id)) {
            $training = Training::find($uuid);
        } else {
            $training = Training::create([
                'org_id' => $this->orgId,
                'name' => $c->name,
                'nickname' => $c->name_short ?: null,
                'description' => $c->description ?: null,
                'initial_only' => $initialOnly,
                'repeating' => $repeating,
                'as_needed' => $asNeeded,
                'std_freq_id' => $stdFreqId,
                'default_hours' => $hours,
                'cert_title' => $c->cert_title ?: null,
                'cert_text' => $certText !== '' ? $certText : null,
                'default_trainer' => $c->trainer ?: null,
                'default_location' => $c->training_location ?: null,
                'default_address' => $c->training_address ?: null,
            ]);
            $this->mapPut('training', (int) $c->id, $training->id);
        }

        $this->courses[(int) $c->id] = [
            'uuid' => $training->id,
            'name' => $c->name,
            'initial_only' => $initialOnly,
            'repeating' => $repeating,
            'as_needed' => $asNeeded,
            'repeat_days' => $repeating ? $days : null,
            'std_freq_name' => $repeating ? $this->freqName($days) : null,
            'hours' => $hours,
            'cert_title' => $c->cert_title ?: null,
            'cert_text' => $certText !== '' ? $certText : null,
        ];
    }

    private function importEmployees(int $limit): void
    {
        $deptName = $this->tw->table('departments')->where('organization_id', $this->twOrgId)->pluck('name', 'id')->all();
        $locName = $this->tw->table('locations')->where('organization_id', $this->twOrgId)->pluck('name', 'id')->all();

        $q = $this->tw->table('employees')
            ->where('organization_id', $this->twOrgId)
            ->where('deleted', 0)
            ->where('removed_date', '0000-00-00 00:00:00')
            ->orderBy('id');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $employees = $q->get();

        $bar = $this->output->createProgressBar($employees->count());
        $bar->setFormat('  employees: %current%/%max% [%bar%] %elapsed%');
        $managerOf = []; // new user uuid => tw manager id (resolved in pass 2)

        foreach ($employees as $e) {
            if (! $uuid = $this->mapGet('user', (int) $e->id)) {
                $user = User::create([
                    'org_id' => $this->orgId,
                    'f_name' => $e->first_name ?: '—',
                    'm_name' => $e->middle_name ?: null,
                    'l_name' => $e->last_name ?: '—',
                    'email' => $this->emailFor($e->email),
                    'password' => null,
                    'status' => ((int) $e->active === 1) ? 'active' : 'disabled',
                    'job_title' => $e->job_title ?: null,
                    'employee_number' => $e->employee_num ?: null,
                    'department' => $deptName[$e->department_id] ?? null,
                    'location' => $locName[$e->location_id] ?? null,
                    'start_date' => $this->d($e->hire_date),
                    'end_date' => $this->d($e->separation_date),
                ]);
                $user->assignRole('None');
                $this->mapPut('user', (int) $e->id, $user->id, $this->orgId);
                $uuid = $user->id;
            }
            if ($e->manager_id) {
                $managerOf[$uuid] = (int) $e->manager_id;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // Pass 2: wire supervisors now that every employee has a uuid.
        $resolved = 0;
        foreach ($managerOf as $userUuid => $twManagerId) {
            if ($supId = $this->mapGet('user', $twManagerId)) {
                User::whereKey($userUuid)->update(['supervisor_id' => $supId]);
                $resolved++;
            }
        }
        $this->line("  supervisors wired: {$resolved}");
    }

    private function importClasses(): void
    {
        $rows = $this->tw->table('classes')
            ->where('organization_id', $this->twOrgId)
            ->where('removed_date', '0000-00-00 00:00:00')
            ->orderBy('id')->get();

        $bar = $this->output->createProgressBar($rows->count());
        $bar->setFormat('  classes: %current%/%max% [%bar%] %elapsed%');

        foreach ($rows as $cl) {
            $course = $this->courses[(int) $cl->course_id] ?? null;
            $start = $this->d($cl->start_date) ?? $this->d($cl->closed_date) ?? now()->toDateString();
            $closed = $this->dt($cl->closed_date);
            $completed = $closed !== null;

            if ($ctUuid = $this->mapGet('class_ct', (int) $cl->id)) {
                $this->classes[(int) $cl->id] = [
                    'ct_uuid' => $ctUuid, 'course_id' => (int) $cl->course_id, 'start_date' => $start,
                ];
                $bar->advance();

                continue;
            }

            $notes = trim(implode("\n\n", array_filter([$cl->description ?? null, $cl->notes ?? null], fn ($v) => is_string($v) && trim($v) !== '')));

            $class = TrainingClass::create([
                'org_id' => $this->orgId,
                'name' => $cl->name ?: ($course['name'] ?? 'Class').' — '.$start,
                'scheduled_date' => $start,
                'start_time' => $this->t($cl->start_time),
                'end_time' => $this->t($cl->end_time),
                'location' => $cl->training_location ?: null,
                'address' => $cl->training_address ?: null,
                'instructor' => $cl->trainer ?: null,
                'show_signature' => (bool) ($cl->show_signature_on_cert ?? false),
                'total_hours' => $cl->hours !== null ? (float) $cl->hours : null,
                'notes' => $notes !== '' ? $notes : null,
                'status' => $completed ? 'completed' : 'scheduled',
                'completion_date' => $completed ? ($this->d($cl->closed_date) ?? $start) : null,
                'completed_at' => $completed ? $closed : null,
            ]);
            $this->mapPut('class', (int) $cl->id, $class->id, $this->orgId);

            // One snapshot topic per class (a TW class has exactly one course).
            $ct = ClassTraining::create([
                'class_id' => $class->id,
                'training_id' => $course['uuid'] ?? null,
                'training_name' => $course['name'] ?? ($cl->name ?: 'Training'),
                'initial_only' => $course['initial_only'] ?? false,
                'repeating' => $course['repeating'] ?? false,
                'as_needed' => $course['as_needed'] ?? false,
                'repeat_days' => $course['repeat_days'] ?? null,
                'std_freq_name' => $course['std_freq_name'] ?? null,
                'hours' => $cl->hours !== null ? (float) $cl->hours : ($course['hours'] ?? null),
                'cert_title' => $course['cert_title'] ?? null,
                'cert_text' => $course['cert_text'] ?? null,
            ]);
            $this->mapPut('class_ct', (int) $cl->id, $ct->id, $this->orgId);

            $this->classes[(int) $cl->id] = [
                'ct_uuid' => $ct->id, 'course_id' => (int) $cl->course_id, 'start_date' => $start,
            ];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    private function importCertifications(int $limit): void
    {
        $q = $this->tw->table('certifications as c')
            ->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('e.organization_id', $this->twOrgId)
            ->where('e.deleted', 0)
            ->where('e.removed_date', '0000-00-00 00:00:00')
            ->where('c.removed_date', '0000-00-00 00:00:00')
            ->orderBy('c.id')
            ->select('c.*');
        if ($limit > 0) {
            $q->limit($limit);
        }
        $rows = $q->get();

        $bar = $this->output->createProgressBar($rows->count());
        $bar->setFormat('  completions: %current%/%max% [%bar%] %elapsed%');
        $skipped = 0;

        foreach ($rows as $cert) {
            if ($this->mapGet('completion', (int) $cert->id)) {
                $bar->advance();

                continue;
            }
            $userId = $this->mapGet('user', (int) $cert->employee_id);
            $course = $this->courses[(int) $cert->course_id] ?? null;
            if (! $userId || ! $course) {
                $skipped++;
                $bar->advance();

                continue;
            }

            $class = $this->classes[(int) $cert->class_id] ?? null;
            $completionDate = $this->d($cert->cert_date) ?? ($class['start_date'] ?? null);
            if ($completionDate === null) {
                $skipped++;
                $bar->advance();

                continue;
            }

            // Link to the class topic only when the cert's course matches the
            // class's course (8 legacy mismatches get no class link).
            $ctId = ($class && $class['course_id'] === (int) $cert->course_id) ? $class['ct_uuid'] : null;

            $completion = Completion::create([
                'org_id' => $this->orgId,
                'user_id' => $userId,
                'module_type' => Training::class,
                'module_id' => $course['uuid'],
                'completion_date' => $completionDate,
                'expire_date' => $this->d($cert->expire_date),
                'cert_id' => $cert->cert_number ?: null,
                'class_training_id' => $ctId,
                'hours' => $course['hours'],
                'notes' => $cert->cert_name ?: null,
            ]);
            $this->mapPut('completion', (int) $cert->id, $completion->id, $this->orgId);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        if ($skipped) {
            $this->warn("  completions skipped (unmapped user/course or no date): {$skipped}");
        }
    }

    // ---- helpers ----------------------------------------------------------

    private function freqName(int $days): string
    {
        return match (true) {
            $days % 365 === 0 => ($days / 365).' Year'.($days === 365 ? '' : 's'),
            $days % 30 === 0 => ($days / 30).' Month'.($days === 30 ? '' : 's'),
            $days % 7 === 0 => ($days / 7).' Week'.($days === 7 ? '' : 's'),
            default => "{$days} Days",
        };
    }

    private function emailFor(?string $raw): ?string
    {
        $e = trim((string) $raw);
        if ($e === '') {
            return null;
        }
        $k = strtolower($e);
        if (isset($this->usedEmails[$k])) {
            return null;
        }
        $this->usedEmails[$k] = true;

        return $e;
    }

    private function d($val): ?string
    {
        if (empty($val) || str_starts_with((string) $val, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse((string) $val)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function dt($val): ?Carbon
    {
        if (empty($val) || str_starts_with((string) $val, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse((string) $val);
        } catch (\Throwable) {
            return null;
        }
    }

    private function t($val): ?string
    {
        if (empty($val) || $val === '00:00:00') {
            return null;
        }

        return substr((string) $val, 0, 5);
    }

    private function mapGet(string $entity, int $twId): ?string
    {
        return DB::table('legacy_tw_map')->where('entity', $entity)->where('tw_id', $twId)->value('new_id');
    }

    private function mapPut(string $entity, int $twId, string $newId, ?string $orgId = null): void
    {
        DB::table('legacy_tw_map')->insert([
            'entity' => $entity, 'tw_id' => $twId, 'new_id' => $newId,
            'new_org_id' => $orgId ?? $this->orgId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** Internal control-flow signal to roll back a --dry-run transaction. */
class DryRunComplete extends \RuntimeException {}
