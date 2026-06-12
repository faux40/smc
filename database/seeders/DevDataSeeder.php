<?php

namespace Database\Seeders;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dev-only realistic data seeder. Layers on top of DevSeeder (which
 * creates John + BG + the default std_frequencies). This seeder gives
 * the BG org enough population to exercise every UI affordance:
 *
 *   - 20 additional users across the role tiers (2 login-capable for
 *     QA across role views; 18 no-login per v14 "tracked but never
 *     authenticates" pattern).
 *   - 8 trainings spanning every timing flag combination + every
 *     std_frequency so timing-driven UI stays exercised.
 *   - 4 requirements covering single + multi-element shapes.
 *   - Assignment fan-out: 2 users get every requirement, ~12 users get
 *     1-3 random ones, the rest get none.
 *
 * Idempotent (sentinel: "Fall Protection" training in BG). Gated to the
 * `local` env from DatabaseSeeder so production never sees this.
 */
class DevDataSeeder extends Seeder
{
    private const ORG_NAME = 'BG';

    private const DEFAULT_PASSWORD = 'Admin1234!';

    /**
     * Login-capable users — predictable emails so the developer can sign
     * in as each role tier without trawling the DB. 2 logins total per
     * the locked seeder plan (90% no-login).
     *
     * @var array<int, array{f_name: string, l_name: string, email: string, role: string}>
     */
    private const LOGIN_USERS = [
        ['f_name' => 'Sarah', 'l_name' => 'Cole', 'email' => 'sarah.cole@demo.local', 'role' => 'SuperAdmin'],
        ['f_name' => 'Mike', 'l_name' => 'Duarte', 'email' => 'mike.duarte@demo.local', 'role' => 'Admin'],
    ];

    /**
     * No-login users — role distribution under the "most as no-login"
     * guidance. Names come from faker so each seed run gets fresh
     * fixtures while the role counts remain stable (the property the
     * test asserts on).
     *
     * @var array<int, array{role: string, count: int}>
     */
    private const NO_LOGIN_ROSTER = [
        ['role' => 'Admin', 'count' => 1],
        ['role' => 'Manager', 'count' => 3],
        ['role' => 'SelfEdit', 'count' => 5],
        ['role' => 'SelfView', 'count' => 3],
        ['role' => 'None', 'count' => 6],
    ];

    /**
     * Eight trainings covering every timing path:
     *   - 6 repeating, hitting all 5 std_frequencies (Annual ×2,
     *     Semi-Annual, Quarterly, Monthly, Every 10 days)
     *   - 1 initial_only
     *   - 1 as_needed only
     *
     * Order matters: REQUIREMENT_MAP references trainings by name.
     *
     * @var array<int, array{name: string, description: string, mode: string, freq?: string}>
     */
    private const TRAININGS = [
        ['name' => 'Fall Protection',     'description' => 'OSHA 1926.500 — protection against falls above 6 ft.', 'mode' => 'repeating', 'freq' => 'Annual', 'hours' => 4],
        ['name' => 'Traffic Control',     'description' => 'Flagger / work-zone traffic-management procedures.',  'mode' => 'repeating', 'freq' => 'Semi-Annual', 'hours' => 2],
        ['name' => 'Lockout/Tagout',      'description' => 'OSHA 1910.147 — control of hazardous energy sources.', 'mode' => 'repeating', 'freq' => 'Annual', 'hours' => 3],
        ['name' => 'Forklift',            'description' => 'OSHA 1910.178 — powered industrial truck operation.',  'mode' => 'repeating', 'freq' => 'Quarterly', 'hours' => 8],
        ['name' => 'Hazmat',              'description' => 'Hazardous-materials handling + DOT placarding.',       'mode' => 'repeating', 'freq' => 'Monthly', 'hours' => 4],
        ['name' => 'First Aid',           'description' => 'Basic first aid + CPR refresher.',                     'mode' => 'repeating', 'freq' => 'Every 10 days', 'hours' => 1.5],
        ['name' => 'Confined Space',      'description' => 'Permit-required confined-space entry training.',       'mode' => 'initial_only', 'hours' => 6],
        ['name' => 'Hearing Conservation', 'description' => 'OSHA 1910.95 — hearing protection on the job.',       'mode' => 'as_needed', 'hours' => null],
    ];

    /**
     * 4 requirements: 2 multi-element + 2 single-element. Element list
     * is the training names from TRAININGS.
     *
     * @var array<int, array{name: string, description: string, trainings: list<string>}>
     */
    private const REQUIREMENT_MAP = [
        ['name' => 'OSHA General',           'description' => 'Site-wide baseline OSHA compliance.', 'trainings' => ['Fall Protection', 'Lockout/Tagout', 'Hazmat']],
        ['name' => 'Forklift Operator',      'description' => 'Authorized powered-industrial-truck operator.', 'trainings' => ['Forklift']],
        ['name' => 'Field Crew',             'description' => 'On-site crew member working in active work zones.', 'trainings' => ['Traffic Control', 'First Aid', 'Hearing Conservation']],
        ['name' => 'Confined Space Entrant', 'description' => 'Authorized confined-space entrant.', 'trainings' => ['Confined Space']],
    ];

    /**
     * Twelve business-relevant tags with hex color pairs (background / text).
     *
     * @var array<int, array{name: string, color: string, font_color: string}>
     */
    private const TAGS = [
        ['name' => 'OSHA Required',          'color' => '#fef2f2', 'font_color' => '#991b1b'],
        ['name' => 'Safety Critical',        'color' => '#fff7ed', 'font_color' => '#9a3412'],
        ['name' => 'Heavy Equipment',        'color' => '#fefce8', 'font_color' => '#854d0e'],
        ['name' => 'Hazardous Materials',    'color' => '#f0fdf4', 'font_color' => '#166534'],
        ['name' => 'Field Work',             'color' => '#eff6ff', 'font_color' => '#1e40af'],
        ['name' => 'New Hire',               'color' => '#f5f3ff', 'font_color' => '#5b21b6'],
        ['name' => 'Certification Required', 'color' => '#fdf4ff', 'font_color' => '#7e22ce'],
        ['name' => 'Annual Review',          'color' => '#f0f9ff', 'font_color' => '#0c4a6e'],
        ['name' => 'DOT Regulated',          'color' => '#fff1f2', 'font_color' => '#9f1239'],
        ['name' => 'Emergency Response',     'color' => '#fff7ed', 'font_color' => '#7c2d12'],
        ['name' => 'Supervisor Required',    'color' => '#f0fdf4', 'font_color' => '#14532d'],
        ['name' => 'Site Specific',          'color' => '#f8fafc', 'font_color' => '#334155'],
    ];

    private const DEPARTMENTS = ['Engineering', 'Operations', 'Safety', 'Maintenance', 'Administration'];

    private const LOCATIONS = ['Site A', 'Site B', 'Main Office', 'Remote'];

    private const JOB_TITLES = [
        'Field Technician', 'Safety Officer', 'Equipment Operator',
        'Lead Technician', 'Site Supervisor', 'Maintenance Technician',
    ];

    public function run(): void
    {
        $org = Organization::query()
            ->withoutGlobalScope('organization')
            ->where('name', self::ORG_NAME)
            ->first();

        if ($org === null) {
            // DevSeeder must run first; bail quietly if BG isn't set up.
            return;
        }

        // Sentinel: if "Fall Protection" already exists in BG, the seeder
        // has run before — skip so re-running migrate:fresh --seed (or
        // re-running this seeder alone) stays idempotent.
        $alreadySeeded = Training::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('name', 'Fall Protection')
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        DB::transaction(function () use ($org): void {
            $freqByName = StdFrequency::query()
                ->withoutGlobalScope('organization')
                ->where('org_id', $org->id)
                ->get()
                ->keyBy('name');

            $users = $this->seedUsers($org);
            $trainings = $this->seedTrainings($org, $freqByName);
            $requirements = $this->seedRequirements($org, $trainings);
            $pairs = $this->pickAssignmentPairs($users, $requirements);
            $this->seedUserProfiles($users);
            $this->seedTags($org, $users, $trainings, $requirements);
            $this->seedTrainingAssignments($org, $trainings, $pairs);
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function seedUsers(Organization $org): Collection
    {
        $users = collect();

        foreach (self::LOGIN_USERS as $row) {
            $users->push(User::factory()
                ->forOrganization($org)
                ->withRole($row['role'])
                ->create([
                    'f_name' => $row['f_name'],
                    'l_name' => $row['l_name'],
                    'email' => $row['email'],
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::DEFAULT_PASSWORD),
                ]));
        }

        foreach (self::NO_LOGIN_ROSTER as $row) {
            for ($i = 0; $i < $row['count']; $i++) {
                $users->push(User::factory()
                    ->forOrganization($org)
                    ->noLogin()
                    ->withRole($row['role'])
                    ->create());
            }
        }

        return $users;
    }

    /**
     * @param  Collection<string, StdFrequency>  $freqByName
     * @return Collection<string, Training>
     */
    private function seedTrainings(Organization $org, Collection $freqByName): Collection
    {
        $trainings = collect();

        foreach (self::TRAININGS as $row) {
            $training = Training::create([
                'org_id' => $org->id,
                'name' => $row['name'],
                'description' => $row['description'],
                'default_hours' => $row['hours'] ?? null,
                'initial_only' => $row['mode'] === 'initial_only',
                'repeating' => $row['mode'] === 'repeating',
                'std_freq_id' => $row['mode'] === 'repeating' ? $freqByName[$row['freq']]->id : null,
                'as_needed' => $row['mode'] === 'as_needed',
            ]);
            $trainings->put($training->name, $training);
        }

        return $trainings;
    }

    /**
     * @param  Collection<string, Training>  $trainings
     * @return Collection<int, Requirement>
     */
    private function seedRequirements(Organization $org, Collection $trainings): Collection
    {
        $requirements = collect();

        foreach (self::REQUIREMENT_MAP as $row) {
            $req = Requirement::create([
                'org_id' => $org->id,
                'name' => $row['name'],
                'description' => $row['description'],
            ]);

            foreach ($row['trainings'] as $trainingName) {
                /** @var Training $t */
                $t = $trainings[$trainingName];
                RqmtElement::create([
                    'org_id' => $org->id,
                    'requirement_id' => $req->id,
                    'module_type' => Training::class,
                    'module_id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'initial_only' => $t->initial_only,
                    'repeating' => $t->repeating,
                    'std_freq_id' => $t->std_freq_id,
                    'as_needed' => $t->as_needed,
                ]);
            }

            $requirements->push($req);
        }

        return $requirements;
    }

    /**
     * Assignment fan-out per the locked plan:
     *   - 2 users get every requirement (the "fully covered" demo case)
     *   - 12 users get 1-3 random requirements
     *   - 6 users get none
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Requirement>  $requirements
     */
    /**
     * Pick which (user, requirement) combinations get assigned (J5: an
     * in-memory list — the legacy assignments table is gone; TAs are the
     * only persisted assignment shape).
     *
     * @return Collection<int, array{user: User, requirement: Requirement}>
     */
    private function pickAssignmentPairs(
        Collection $users,
        Collection $requirements,
    ): Collection {
        $shuffled = $users->shuffle();

        $fullyCovered = $shuffled->take(2);
        $partial = $shuffled->slice(2, 12);
        // The remainder (~6 users) get no assignments.

        $pairs = collect();

        foreach ($fullyCovered as $user) {
            foreach ($requirements as $req) {
                $pairs->push(['user' => $user, 'requirement' => $req]);
            }
        }

        foreach ($partial as $user) {
            $count = random_int(1, 3);
            foreach ($requirements->shuffle()->take($count) as $req) {
                $pairs->push(['user' => $user, 'requirement' => $req]);
            }
        }

        return $pairs;
    }

    /**
     * Update ~75 % of users with profile fields (employee number, department,
     * location, job title). Half of those also get a supervisor from the
     * Manager tier.
     *
     * @param  Collection<int, User>  $users
     */
    private function seedUserProfiles(Collection $users): void
    {
        $managerIds = User::query()
            ->withoutGlobalScope('organization')
            ->whereIn('id', $users->pluck('id'))
            ->whereHas('roles', fn ($q) => $q->where('name', 'Manager'))
            ->pluck('id')
            ->values()
            ->all();

        $count75 = (int) round($users->count() * 0.75);
        $count50ofThat = (int) round($count75 * 0.50);

        foreach ($users->take($count75) as $idx => $user) {
            $user->update([
                'employee_number' => 'EMP-' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT),
                'department' => self::DEPARTMENTS[$idx % count(self::DEPARTMENTS)],
                'location' => self::LOCATIONS[$idx % count(self::LOCATIONS)],
                'job_title' => self::JOB_TITLES[$idx % count(self::JOB_TITLES)],
                'supervisor_id' => ($idx < $count50ofThat && count($managerIds) > 0)
                    ? $managerIds[$idx % count($managerIds)]
                    : null,
            ]);
        }
    }

    /**
     * Create 12 tags and attach them to trainings, requirements, and users.
     *
     * @param  Collection<string, Training>   $trainings    keyed by name
     * @param  Collection<int, Requirement>  $requirements
     * @param  Collection<int, User>         $users
     */
    private function seedTags(
        Organization $org,
        Collection $users,
        Collection $trainings,
        Collection $requirements,
    ): void {
        $tagIds = [];
        foreach (self::TAGS as $td) {
            $tag = Tag::create([
                'org_id' => $org->id,
                'name' => $td['name'],
                'color' => $td['color'],
                'font_color' => $td['font_color'],
            ]);
            $tagIds[] = $tag->id;
        }

        $allIds = collect($tagIds);

        foreach ($trainings as $training) {
            $training->tags()->syncWithoutDetaching(
                $allIds->shuffle()->take(random_int(2, 3))->all(),
            );
        }

        foreach ($requirements as $req) {
            $req->tags()->syncWithoutDetaching(
                $allIds->shuffle()->take(random_int(1, 2))->all(),
            );
        }

        $count75 = (int) round($users->count() * 0.75);
        foreach ($users->take($count75) as $user) {
            $user->tags()->syncWithoutDetaching(
                $allIds->shuffle()->take(random_int(2, 3))->all(),
            );
        }
    }

    /**
     * Expand each (user, requirement) assignment into individual
     * (user, training) TrainingAssignment rows with varied expiry statuses.
     * Completions are created for the three "completed" status buckets;
     * the CompletionObserver fires normally and stamps the correct dates
     * onto each TrainingAssignment via RecalculateTrainingStatus.
     *
     * Status distribution (by index % 4):
     *   0 → never-started  (null, null)
     *   1 → expired        (completed 2 yrs ago, expired 30 days ago)
     *   2 → expiring soon  (completed 11 months ago, expires in 20 days)
     *   3 → ok             (completed 6 months ago, expires in 180 days)
     *
     * @param  Collection<string, Training>  $trainings  keyed by name
     */
    private function seedTrainingAssignments(
        Organization $org,
        Collection $trainings,
        Collection $pairs,
    ): void {
        $reqToTrainings = collect(self::REQUIREMENT_MAP)
            ->mapWithKeys(fn ($rm) => [$rm['name'] => $rm['trainings']]);

        // Maps "user_id|training_id" → training_assignment_id for dedup + multi-source linking.
        $seen = [];
        $index = 0;

        foreach ($pairs as $pair) {
            $trainingNames = $reqToTrainings->get($pair['requirement']->name, []);

            foreach ($trainingNames as $trainingName) {
                $training = $trainings->get($trainingName);
                if ($training === null) {
                    continue;
                }

                $key = $pair['user']->id . '|' . $training->id;

                if (isset($seen[$key])) {
                    // TA exists from another requirement — add a second source row.
                    AssignmentSource::create([
                        'training_assignment_id' => $seen[$key],
                        'sourceable_type'        => Requirement::class,
                        'sourceable_id'          => $pair['requirement']->id,
                        'added_at'               => now(),
                    ]);
                    continue;
                }

                $ta = TrainingAssignment::create([
                    'org_id'            => $org->id,
                    'user_id'           => $pair['user']->id,
                    'training_id'       => $training->id,
                    'name'              => $trainingName,
                    'last_completed_at' => null,
                    'expires_at'        => null,
                ]);

                AssignmentSource::create([
                    'training_assignment_id' => $ta->id,
                    'sourceable_type'        => Requirement::class,
                    'sourceable_id'          => $pair['requirement']->id,
                    'added_at'               => now(),
                ]);

                $seen[$key] = $ta->id;

                [$completionDate, $expireDate] = $this->statusDatesForIndex($index++);

                if ($completionDate !== null) {
                    // Creating the Completion triggers CompletionObserver →
                    // RecalculateTrainingStatus, which stamps last_completed_at
                    // and expires_at on the TrainingAssignment using expire_date.
                    Completion::create([
                        'org_id' => $org->id,
                        'user_id' => $pair['user']->id,
                        'module_type' => Training::class,
                        'module_id' => $training->id,
                        'completion_date' => $completionDate,
                        'expire_date' => $expireDate,
                    ]);
                }
            }
        }
    }

    /**
     * Return [completion_date, expire_date] strings (or nulls) for the given
     * status bucket (index % 4).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function statusDatesForIndex(int $index): array
    {
        return match ($index % 4) {
            0 => [null, null],
            1 => [now()->subDays(730)->toDateString(), now()->subDays(30)->toDateString()],
            2 => [now()->subDays(335)->toDateString(), now()->addDays(20)->toDateString()],
            default => [now()->subDays(180)->toDateString(), now()->addDays(180)->toDateString()],
        };
    }
}
