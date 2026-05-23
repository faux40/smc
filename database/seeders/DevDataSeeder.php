<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Training;
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
     * @var array<int, array{name: string, description: string, trainings: array<int, string>}>
     */
    private const REQUIREMENT_MAP = [
        ['name' => 'OSHA General',           'description' => 'Site-wide baseline OSHA compliance.', 'trainings' => ['Fall Protection', 'Lockout/Tagout', 'Hazmat']],
        ['name' => 'Forklift Operator',      'description' => 'Authorized powered-industrial-truck operator.', 'trainings' => ['Forklift']],
        ['name' => 'Field Crew',             'description' => 'On-site crew member working in active work zones.', 'trainings' => ['Traffic Control', 'First Aid', 'Hearing Conservation']],
        ['name' => 'Confined Space Entrant', 'description' => 'Authorized confined-space entrant.', 'trainings' => ['Confined Space']],
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
            $this->seedAssignments($org, $users, $requirements, $freqByName);
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
     * Timing is uniform (repeating + Annual) so all assignments validate
     * cleanly; per-(user, requirement) timing tweaks are an admin-UX
     * concern, not a seeder concern.
     *
     * @param  Collection<int, User>  $users
     * @param  Collection<int, Requirement>  $requirements
     * @param  Collection<string, StdFrequency>  $freqByName
     */
    private function seedAssignments(
        Organization $org,
        Collection $users,
        Collection $requirements,
        Collection $freqByName,
    ): void {
        $annualId = $freqByName['Annual']->id;
        $shuffled = $users->shuffle();

        $fullyCovered = $shuffled->take(2);
        $partial = $shuffled->slice(2, 12);
        // The remainder (~6 users) get no assignments.

        foreach ($fullyCovered as $user) {
            foreach ($requirements as $req) {
                $this->createAssignment($org->id, $user, $req, $annualId);
            }
        }

        foreach ($partial as $user) {
            $count = random_int(1, 3);
            foreach ($requirements->shuffle()->take($count) as $req) {
                $this->createAssignment($org->id, $user, $req, $annualId);
            }
        }
    }

    private function createAssignment(string $orgId, User $user, Requirement $req, string $stdFreqId): void
    {
        Assignment::create([
            'org_id' => $orgId,
            'user_id' => $user->id,
            'requirement_id' => $req->id,
            'name' => $req->name,
            'description' => $req->description,
            'initial_only' => false,
            'repeating' => true,
            'std_freq_id' => $stdFreqId,
            'as_needed' => false,
            'start_date' => now()->subDays(random_int(30, 365))->toDateString(),
            'end_date' => null,
        ]);
    }
}
