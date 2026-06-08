<?php

namespace Tests\Feature\Tenancy;

use App\Models\Assignment;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 14 dashboard endpoints. The status-math is exercised by
 * UserComplianceTest; here we cover routing, org scope, role gates,
 * and the JSON envelope each widget consumes.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function scaffoldOrg(): array
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        return [$org, $manager, $training];
    }

    public function test_summary_returns_expected_envelope(): void
    {
        [$org, $manager] = $this->scaffoldOrg();

        $response = $this->actingAs($manager)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('counts', $response);
        $this->assertArrayHasKey('total_assignments', $response);
        $this->assertArrayHasKey('total_users', $response);
        $this->assertArrayHasKey('users_with_overdue', $response);
        foreach (['overdue', 'due_soon', 'current', 'never_started', 'inactive'] as $bucket) {
            $this->assertArrayHasKey($bucket, $response['counts']);
        }
    }

    public function test_summary_is_org_scoped(): void
    {
        [$orgA, $managerA] = $this->scaffoldOrg();
        [$orgB] = $this->scaffoldOrg();
        // Build a user in orgB with no assignments — should NOT be counted
        // in orgA's summary.
        User::factory()->for($orgB, 'organization')->create();
        // A user in orgA contributes to total_users.
        User::factory()->for($orgA, 'organization')->create();

        $response = $this->actingAs($managerA)
            ->getJson('/api/dashboard/summary')
            ->assertOk()
            ->json();

        // manager + 1 extra in orgA = 2.
        $this->assertSame(2, $response['total_users']);
    }

    public function test_overdue_users_lists_only_users_with_overdue_items(): void
    {
        [$org, $manager, $training] = $this->scaffoldOrg();
        $userOverdue = User::factory()->for($org, 'organization')->create(['f_name' => 'Over', 'l_name' => 'Due']);
        $userCurrent = User::factory()->for($org, 'organization')->create();

        // Overdue setup: initial_only element, never completed, past start_date.
        $reqOverdue = Requirement::factory()->for($org, 'organization')->create();
        RqmtElement::factory()
            ->for($org, 'organization')
            ->for($reqOverdue, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();
        Assignment::factory()
            ->for($org, 'organization')
            ->for($userOverdue, 'user')
            ->for($reqOverdue, 'requirement')
            ->create(['start_date' => now()->subMonths(2)->toDateString()]);

        // Current setup: as_needed element → always current → omitted from
        // overdue list.
        $reqCurrent = Requirement::factory()->for($org, 'organization')->create();
        RqmtElement::factory()
            ->for($org, 'organization')
            ->for($reqCurrent, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'as_needed' => true,
                'repeating' => false,
                'initial_only' => false,
            ])
            ->create();
        Assignment::factory()
            ->for($org, 'organization')
            ->for($userCurrent, 'user')
            ->for($reqCurrent, 'requirement')
            ->create();

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/overdue-users')
            ->assertOk()
            ->json();

        $this->assertCount(1, $rows);
        $this->assertSame($userOverdue->id, $rows[0]['user_id']);
        $this->assertSame(1, $rows[0]['overdue_count']);
    }

    public function test_overdue_users_does_not_leak_cross_org(): void
    {
        [, $managerA] = $this->scaffoldOrg();
        [$orgB, , $trainingB] = $this->scaffoldOrg();

        // An overdue user in orgB — must not appear in orgA's dashboard.
        $otherUser = User::factory()->for($orgB, 'organization')->create();
        $req = Requirement::factory()->for($orgB, 'organization')->create();
        RqmtElement::factory()
            ->for($orgB, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $trainingB->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();
        Assignment::factory()
            ->for($orgB, 'organization')
            ->for($otherUser, 'user')
            ->for($req, 'requirement')
            ->create(['start_date' => now()->subMonths(2)->toDateString()]);

        $rows = $this->actingAs($managerA)
            ->getJson('/api/dashboard/overdue-users')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_recent_completions_returns_newest_first(): void
    {
        [$org, $manager, $training] = $this->scaffoldOrg();
        $u = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->create();

        $older = Completion::factory()
            ->for($org, 'organization')
            ->for($u, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-03-01',
            ])
            ->create();
        $older->rqmtElements()->sync([$element->id]);

        $newer = Completion::factory()
            ->for($org, 'organization')
            ->for($u, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-05-01',
            ])
            ->create();
        $newer->rqmtElements()->sync([$element->id]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/recent-completions')
            ->assertOk()
            ->json();

        $this->assertSame($newer->id, $rows[0]['id']);
        $this->assertSame($older->id, $rows[1]['id']);
    }

    public function test_recent_completions_is_org_scoped(): void
    {
        [, $managerA] = $this->scaffoldOrg();
        [$orgB, , $trainingB] = $this->scaffoldOrg();
        $userB = User::factory()->for($orgB, 'organization')->create();
        Completion::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();

        $rows = $this->actingAs($managerA)
            ->getJson('/api/dashboard/recent-completions')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_endpoints_reject_self_edit_role(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        foreach (['summary', 'overdue-users', 'due-soon', 'recent-completions'] as $path) {
            $this->actingAs($self)
                ->getJson("/api/dashboard/{$path}")
                ->assertForbidden();
        }
    }

    public function test_endpoints_reject_guest(): void
    {
        foreach (['summary', 'overdue-users', 'due-soon', 'recent-completions', 'training-due-soon'] as $path) {
            $this->getJson("/api/dashboard/{$path}")
                ->assertUnauthorized();
        }
    }

    // -----------------------------------------------------------------------
    // training-due-soon
    // -----------------------------------------------------------------------

    public function test_training_due_soon_returns_rows_in_window(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Test']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift Safety']);

        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($training, 'training')
            ->create([
                'name' => 'Forklift Safety',
                'expires_at' => now()->addDays(30)->toDateString(),
            ]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows[0]['user_id']);
        $this->assertSame('Forklift Safety', $rows[0]['training_name']);
        $this->assertArrayHasKey('expires_at', $rows[0]);
        $this->assertArrayHasKey('user_name', $rows[0]);
    }

    public function test_training_due_soon_excludes_rows_outside_window(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        // Default window = 60 days. This row expires in 90 days — outside.
        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($training, 'training')
            ->create(['expires_at' => now()->addDays(90)->toDateString()]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_training_due_soon_excludes_already_expired(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($training, 'training')
            ->create(['expires_at' => now()->subDay()->toDateString()]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_training_due_soon_respects_org_threshold(): void
    {
        $org = Organization::factory()->create(['training_thresholds' => ['due_soon_days' => 14]]);
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $trainingA = Training::factory()->for($org, 'organization')->create();
        $trainingB = Training::factory()->for($org, 'organization')->create();

        // 10 days out — within the 14-day custom window.
        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($trainingA, 'training')
            ->create(['expires_at' => now()->addDays(10)->toDateString()]);

        // 20 days out — outside the 14-day custom window.
        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($trainingB, 'training')
            ->create(['expires_at' => now()->addDays(20)->toDateString()]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(1, $rows);
    }

    public function test_training_due_soon_is_org_scoped(): void
    {
        [, $managerA] = $this->scaffoldOrg();
        [$orgB] = $this->scaffoldOrg();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();

        TrainingAssignment::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->for($trainingB, 'training')
            ->create(['expires_at' => now()->addDays(5)->toDateString()]);

        $rows = $this->actingAs($managerA)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(0, $rows);
    }

    public function test_training_due_soon_rejects_self_edit_role(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertForbidden();
    }

    public function test_training_due_soon_ordered_by_expires_at_asc(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();
        $trainingA = Training::factory()->for($org, 'organization')->create();
        $trainingB = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($trainingA, 'training')
            ->create(['expires_at' => now()->addDays(40)->toDateString()]);

        TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($trainingB, 'training')
            ->create(['expires_at' => now()->addDays(10)->toDateString()]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/training-due-soon')
            ->assertOk()
            ->json();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['expires_at'] < $rows[1]['expires_at']);
    }
}
