<?php

namespace Tests\Feature\Seeding;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\DevDataSeeder;
use Database\Seeders\DevSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Invariant tests for DevDataSeeder. Asserts the shape and the
 * distribution rules — not specific names — so the seeder can rotate
 * faker data without breaking the suite.
 */
class DevDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ORG_NAME = 'BG';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(DevSeeder::class);
        $this->seed(DevDataSeeder::class);
    }

    private function bgOrg(): Organization
    {
        return Organization::query()
            ->withoutGlobalScope('organization')
            ->where('name', self::ORG_NAME)
            ->firstOrFail();
    }

    public function test_seeds_twenty_additional_users_with_expected_role_distribution(): void
    {
        $org = $this->bgOrg();

        // 20 new users (in addition to John from DevSeeder = 21 total).
        $this->assertSame(
            21,
            User::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
        );

        $roleCount = fn (string $role) => User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->count();

        // John (Owner) + the new roster.
        $this->assertSame(1, $roleCount('Owner'));
        $this->assertSame(1, $roleCount('SuperAdmin'));
        $this->assertSame(2, $roleCount('Admin'));
        $this->assertSame(3, $roleCount('Manager'));
        $this->assertSame(5, $roleCount('SelfEdit'));
        $this->assertSame(3, $roleCount('SelfView'));
        $this->assertSame(6, $roleCount('None'));
    }

    public function test_only_two_new_users_are_login_capable(): void
    {
        $org = $this->bgOrg();

        // Login-capable = email AND password set. Excludes John (his
        // shape is locked by DevSeeder, not DevDataSeeder).
        $logins = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereNotNull('email')
            ->whereNotNull('password')
            ->where('email', '!=', 'john@barrittgroup.com')
            ->get();

        $this->assertCount(2, $logins);
        $this->assertContains('sarah.cole@demo.local', $logins->pluck('email')->all());
        $this->assertContains('mike.duarte@demo.local', $logins->pluck('email')->all());

        foreach ($logins as $u) {
            $this->assertTrue(
                Hash::check('Admin1234!', $u->password),
                "Login user {$u->email} should have the default dev password.",
            );
        }
    }

    public function test_seeds_eight_trainings_covering_every_timing_path(): void
    {
        $org = $this->bgOrg();
        $trainings = Training::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->get();

        $this->assertCount(8, $trainings);

        $repeating = $trainings->where('repeating', true);
        $this->assertSame(6, $repeating->count(), 'Six trainings should be repeating across std_frequencies.');
        $this->assertSame(1, $trainings->where('initial_only', true)->count());
        $this->assertSame(1, $trainings->where('as_needed', true)->count());

        // Every std_frequency in BG should back at least one training.
        $usedFreqIds = $repeating->pluck('std_freq_id')->unique()->values();
        $bgFreqCount = StdFrequency::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->count();
        $this->assertSame($bgFreqCount, $usedFreqIds->count());
    }

    public function test_seeds_four_requirements_with_single_and_multi_element_shapes(): void
    {
        $org = $this->bgOrg();
        $requirements = Requirement::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->withCount('elements')
            ->get();

        $this->assertCount(4, $requirements);

        $elementsCounts = $requirements->pluck('elements_count')->sort()->values()->all();
        // 2 single + 2 multi, total elements = 1 + 1 + 3 + 3 = 8.
        $this->assertSame([1, 1, 3, 3], $elementsCounts);
        $this->assertSame(
            8,
            RqmtElement::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
        );
    }

    public function test_assignment_fanout_matches_distribution_plan(): void
    {
        $org = $this->bgOrg();
        $newUsers = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('email', '!=', 'john@barrittgroup.com')
            ->orWhereNull('email')
            ->get()
            ->filter(fn (User $u) => $u->email !== 'john@barrittgroup.com');

        // Count assignments per user.
        $perUser = Assignment::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->count());

        // Two users are fully covered (4 requirements each).
        $fullyCovered = $perUser->filter(fn ($n) => $n === 4);
        $this->assertSame(2, $fullyCovered->count(), 'Exactly 2 users should have every requirement.');

        // Twelve users hold 1–3 assignments.
        $partial = $perUser->filter(fn ($n) => $n >= 1 && $n <= 3);
        $this->assertSame(12, $partial->count(), 'Exactly 12 users should have 1–3 assignments.');

        // Six users hold zero — they're absent from the groupBy.
        $usersWithAny = $perUser->keys();
        $usersWithNone = $newUsers->pluck('id')->diff($usersWithAny);
        $this->assertSame(6, $usersWithNone->count(), 'Exactly 6 users should have no assignments.');
    }

    public function test_seeds_training_assignments_for_assigned_users(): void
    {
        $org = $this->bgOrg();
        $count = TrainingAssignment::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->count();
        $this->assertGreaterThan(0, $count, 'DevDataSeeder should create at least one TrainingAssignment.');
    }

    public function test_seeds_all_four_expiry_statuses(): void
    {
        $org = $this->bgOrg();
        $tas = TrainingAssignment::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->get();

        $today = now()->toDateString();

        $neverStarted = $tas->whereNull('last_completed_at');
        $expired = $tas->filter(
            fn (TrainingAssignment $ta) => $ta->last_completed_at !== null
                && $ta->expires_at !== null
                && $ta->expires_at->toDateString() < $today,
        );
        $expiring = $tas->filter(
            fn (TrainingAssignment $ta) => $ta->last_completed_at !== null
                && $ta->expires_at !== null
                && $ta->expires_at->toDateString() >= $today
                && $ta->expires_at->toDateString() <= now()->addDays(60)->toDateString(),
        );
        $ok = $tas->filter(
            fn (TrainingAssignment $ta) => $ta->last_completed_at !== null
                && $ta->expires_at !== null
                && $ta->expires_at->toDateString() > now()->addDays(60)->toDateString(),
        );

        $this->assertGreaterThan(0, $neverStarted->count(), 'Should have at least one never-started assignment.');
        $this->assertGreaterThan(0, $expired->count(), 'Should have at least one expired assignment.');
        $this->assertGreaterThan(0, $expiring->count(), 'Should have at least one expiring-soon assignment.');
        $this->assertGreaterThan(0, $ok->count(), 'Should have at least one current (ok) assignment.');
    }

    public function test_seeds_twelve_tags(): void
    {
        $org = $this->bgOrg();
        $this->assertSame(
            12,
            Tag::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
            'DevDataSeeder should create exactly 12 tags.',
        );
    }

    public function test_seeds_completions_for_completed_assignments(): void
    {
        $org = $this->bgOrg();

        $completedTaCount = TrainingAssignment::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereNotNull('last_completed_at')
            ->count();

        $completionCount = Completion::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->where('module_type', Training::class)
            ->count();

        $this->assertGreaterThan(0, $completionCount, 'Should have at least one Completion.');
        $this->assertSame(
            $completedTaCount,
            $completionCount,
            'Completion count should match training assignments with last_completed_at set.',
        );
    }

    public function test_seeds_user_profiles_for_majority_of_users(): void
    {
        $org = $this->bgOrg();

        $total = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->count();

        $withProfiles = User::query()
            ->withoutGlobalScope('organization')
            ->where('org_id', $org->id)
            ->whereNotNull('employee_number')
            ->count();

        // Seeder targets 75%; allow floor of 70% to be robust to rounding.
        $this->assertGreaterThanOrEqual(
            (int) floor($total * 0.70),
            $withProfiles,
            'At least 70% of users should have an employee_number.',
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        // setUp already ran the seeder once. Re-run twice more.
        $this->seed(DevDataSeeder::class);
        $this->seed(DevDataSeeder::class);

        $org = $this->bgOrg();
        $this->assertSame(
            21,
            User::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
        );
        $this->assertSame(
            8,
            Training::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
        );
        $this->assertSame(
            4,
            Requirement::query()->withoutGlobalScope('organization')->where('org_id', $org->id)->count(),
        );
    }
}
