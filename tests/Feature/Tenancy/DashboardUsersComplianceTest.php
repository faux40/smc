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

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json('data');
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

        $rows = $this->actingAs($managerA)->getJson(self::URL)->assertOk()->json('data');

        $this->assertCount(1, $rows); // only orgA's manager
    }

    public function test_requires_manager_or_higher(): void
    {
        $org = Organization::factory()->create();
        $none = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($none)->getJson(self::URL)->assertForbidden();
    }

    public function test_paginates_with_meta_and_clamps_per_page(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        User::factory()->for($org, 'organization')->count(5)->create(); // 6 incl. manager

        $this->actingAs($manager)
            ->getJson(self::URL.'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3);

        $this->actingAs($manager)
            ->getJson(self::URL.'?per_page=9999')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_searches_by_name_or_email(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        User::factory()->for($org, 'organization')->create(['f_name' => 'Forklift', 'l_name' => 'Frank']);
        User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Andersen']);

        $rows = $this->actingAs($manager)->getJson(self::URL.'?q=forklift')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Forklift', (string) $rows[0]['name']);
    }

    public function test_sorts_by_overdue_count_descending_by_default(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $worst = User::factory()->for($org, 'organization')->create();
        $this->makeOverdue($org, $worst);

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json('data');
        // Most-overdue user first.
        $this->assertSame($worst->id, $rows[0]['user_id']);
    }

    public function test_includes_attached_tag_ids(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $tag = Tag::factory()->for($org, 'organization')->create();
        $manager->tags()->attach($tag->id);

        $rows = $this->actingAs($manager)->getJson(self::URL)->assertOk()->json('data');
        $byId = collect($rows)->keyBy('user_id');

        $this->assertContains($tag->id, $byId[$manager->id]['tag_ids']);
    }
}
