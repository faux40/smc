<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard's full-width all-users compliance list. One row per org user
 * with per-status counts + an overall status + tag ids, Manager+ gated.
 */
class DashboardUsersComplianceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/dashboard/users-compliance';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    /** Give $user one expired training assignment → overdue. */
    private function makeOverdue(Organization $org, User $user): void
    {
        $training = Training::factory()->for($org, 'organization')->create();
        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'last_completed_at' => now()->subYear(),
            'expires_at' => now()->subDays(10),
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);
    }

    public function test_returns_a_row_per_org_user_with_counts_and_overall_status(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $overdueUser = User::factory()->for($org, 'organization')->create();
        $this->makeOverdue($org, $overdueUser);

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json();
        $byId = collect($rows)->keyBy('user_id');

        // Overdue user surfaces as overdue with a count of 1.
        $this->assertSame('overdue', $byId[$overdueUser->id]['overall_status']);
        $this->assertSame(1, $byId[$overdueUser->id]['counts']['overdue']);

        // The manager has no assignments → 'none', all counts zero.
        $this->assertSame('none', $byId[$manager->id]['overall_status']);
        $this->assertSame(0, $byId[$manager->id]['counts']['overdue']);
    }

    public function test_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        User::factory()->for($orgB, 'organization')->count(3)->create();

        $rows = $this->actingAs($managerA)->getJson(self::URL)->assertOk()->json();

        $this->assertCount(1, $rows); // only orgA's manager
    }

    public function test_requires_manager_or_higher(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($none)->getJson(self::URL)->assertForbidden();
    }

    public function test_includes_attached_tag_ids(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $tag = Tag::factory()->for($org, 'organization')->create();
        $manager->tags()->attach($tag->id);

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json();
        $byId = collect($rows)->keyBy('user_id');

        $this->assertContains($tag->id, $byId[$manager->id]['tag_ids']);
    }
}
