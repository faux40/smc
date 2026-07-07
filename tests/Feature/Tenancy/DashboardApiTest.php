<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
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
        foreach (['overdue', 'due_soon', 'current', 'not_started', 'as_needed'] as $bucket) {
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

        foreach (['summary', 'needs-action', 'recent-completions'] as $path) {
            $this->actingAs($self)
                ->getJson("/api/dashboard/{$path}")
                ->assertForbidden();
        }
    }

    public function test_endpoints_reject_guest(): void
    {
        foreach (['summary', 'needs-action', 'recent-completions'] as $path) {
            $this->getJson("/api/dashboard/{$path}")
                ->assertUnauthorized();
        }
    }

    // -----------------------------------------------------------------------
    // needs-action (K2) — server-paged actionable rows for the manager widget.
    // Status math itself is covered by TrainingStatusServiceTest; here we
    // cover the {data, meta} envelope, filtering, ordering, chips, window,
    // pagination, status filter, search, and scope.
    // -----------------------------------------------------------------------

    private function actionTa(
        Organization $org,
        User $user,
        string $name,
        array $attrs = [],
        ?Requirement $sourceReq = null,
    ): TrainingAssignment {
        $training = Training::factory()->for($org, 'organization')->create(['name' => $name]);
        $ta = TrainingAssignment::factory()->create(array_merge([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $name,
        ], $attrs));

        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => $sourceReq ? Requirement::class : null,
            'sourceable_id' => $sourceReq?->id,
            'added_at' => now(),
        ]);

        return $ta;
    }

    public function test_needs_action_returns_only_actionable_rows(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();

        $overdue = $this->actionTa($org, $user, 'Overdue T', [
            'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays(5),
        ]);
        $dueSoon = $this->actionTa($org, $user, 'DueSoon T', [
            'last_completed_at' => now()->subMonths(11), 'expires_at' => now()->addDays(10),
        ]);
        $notStarted = $this->actionTa($org, $user, 'NotStarted T');
        $this->actionTa($org, $user, 'Current T', [
            'last_completed_at' => now()->subMonth(), 'expires_at' => now()->addDays(300),
        ]);
        $this->actionTa($org, $user, 'AsNeeded T', ['as_needed_only' => true]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [$overdue->id, $notStarted->id, $dueSoon->id],
            array_column($rows, 'id'),
        );

        $row = $rows[0];
        foreach (['id', 'user_id', 'user_name', 'training_id', 'training_name', 'status', 'expires_at', 'days_until_due', 'sources'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('overdue', $row['status']);
        $this->assertSame(-5, $row['days_until_due']);
    }

    public function test_needs_action_orders_most_overdue_first_within_bucket(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();

        $mild = $this->actionTa($org, $user, 'Mildly overdue', [
            'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays(2),
        ]);
        $severe = $this->actionTa($org, $user, 'Severely overdue', [
            'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays(60),
        ]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk()
            ->json('data');

        $this->assertSame([$severe->id, $mild->id], array_column($rows, 'id'));
    }

    public function test_needs_action_includes_source_chips(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'OSHA General']);

        $viaReq = $this->actionTa($org, $user, 'Via Req', [], $req);
        $direct = $this->actionTa($org, $user, 'Direct T');

        $rows = collect($this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame(
            [['type' => 'requirement', 'id' => $req->id, 'name' => 'OSHA General']],
            $rows[$viaReq->id]['sources'],
        );
        $this->assertSame(
            [['type' => 'direct', 'id' => null, 'name' => null]],
            $rows[$direct->id]['sources'],
        );
    }

    public function test_needs_action_respects_org_window(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $org->update(['training_thresholds' => ['expiring_soon_days' => 90]]);
        $user = User::factory()->for($org, 'organization')->create();

        // 45 days out: due_soon under a 90-day window, current under 30.
        $ta = $this->actionTa($org, $user, 'Wide window T', [
            'last_completed_at' => now()->subMonths(10), 'expires_at' => now()->addDays(45),
        ]);

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk()
            ->json('data');

        $this->assertSame([$ta->id], array_column($rows, 'id'));
        $this->assertSame('due_soon', $rows[0]['status']);
    }

    public function test_needs_action_is_org_scoped(): void
    {
        [, $managerA] = $this->scaffoldOrg();
        [$orgB] = $this->scaffoldOrg();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $this->actionTa($orgB, $userB, 'Foreign overdue', [
            'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays(5),
        ]);

        $rows = $this->actingAs($managerA)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_needs_action_paginates_with_meta_and_clamps_per_page(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();

        // 3 overdue rows, 2 per page → 2 pages.
        foreach (range(1, 3) as $i) {
            $this->actionTa($org, $user, "Overdue {$i}", [
                'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays($i),
            ]);
        }

        $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_needs_action_filters_by_status_in_sql(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $user = User::factory()->for($org, 'organization')->create();

        $overdue = $this->actionTa($org, $user, 'Overdue T', [
            'last_completed_at' => now()->subYear(), 'expires_at' => now()->subDays(5),
        ]);
        $this->actionTa($org, $user, 'NotStarted T');

        $rows = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action?status=overdue')
            ->assertOk()
            ->json('data');

        $this->assertSame([$overdue->id], array_column($rows, 'id'));
    }

    public function test_needs_action_searches_user_and_training_name_in_sql(): void
    {
        [$org, $manager] = $this->scaffoldOrg();
        $alice = User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Aardvark']);
        $bob = User::factory()->for($org, 'organization')->create(['f_name' => 'Bob', 'l_name' => 'Badger']);

        $forkliftForAlice = $this->actionTa($org, $alice, 'Forklift Safety');
        $fallForBob = $this->actionTa($org, $bob, 'Fall Protection');

        // Match on training name.
        $byTraining = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action?q=forklift')
            ->assertOk()
            ->json('data');
        $this->assertSame([$forkliftForAlice->id], array_column($byTraining, 'id'));

        // Match on user name.
        $byUser = $this->actingAs($manager)
            ->getJson('/api/dashboard/needs-action?q=badger')
            ->assertOk()
            ->json('data');
        $this->assertSame([$fallForBob->id], array_column($byUser, 'id'));
    }
}
