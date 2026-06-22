<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ComplianceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    private function ta(Organization $org, Training $training, string $status): void
    {
        $user = User::factory()->for($org, 'organization')->create();
        TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'status' => $status,
        ]);
    }

    public function test_manager_sees_the_shell_and_rollup_endpoints(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Fall Protection']);
        $this->ta($org, $training, 'overdue');
        $this->ta($org, $training, 'current');

        $this->actingAs($manager)
            ->get(route('compliance.page'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('compliance/Index'));

        $this->actingAs($manager)
            ->getJson(route('compliance.by-training'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Fall Protection')
            ->assertJsonPath('data.0.total', 2)
            ->assertJsonPath('data.0.counts.overdue', 1)
            ->assertJsonPath('data.0.counts.current', 1);

        $this->actingAs($manager)
            ->getJson(route('compliance.by-requirement'))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);

        // The direct-only assignments above are "not required".
        $this->actingAs($manager)
            ->getJson(route('compliance.not-required'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Fall Protection')
            ->assertJsonPath('data.0.total', 2);
    }

    public function test_training_drilldown_endpoint_lists_users(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $this->ta($org, $training, 'overdue');

        $this->actingAs($manager)
            ->getJson(route('compliance.training-users', $training))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'overdue')
            ->assertJsonStructure(['data' => [['user_id', 'name', 'status', 'expires_at', 'last_completed_at']], 'meta']);
    }

    public function test_training_detail_shell_carries_counts(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Hazwoper']);
        $this->ta($org, $training, 'overdue');
        $this->ta($org, $training, 'current');

        $this->actingAs($manager)
            ->get(route('compliance.training', $training))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('compliance/TrainingDetail')
                ->where('training.id', $training->id)
                ->where('training.name', 'Hazwoper')
                ->where('counts.overdue', 1)
                ->where('counts.current', 1)
                ->where('counts.total', 2));
    }

    public function test_training_users_endpoint_filters_by_status(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $this->ta($org, $training, 'overdue');
        $this->ta($org, $training, 'current');

        $this->actingAs($manager)
            ->getJson(route('compliance.training-users', [$training, 'status' => 'overdue']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'overdue');
    }

    public function test_not_required_users_drilldown_endpoint(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        // A direct-only (not required) completed assignment.
        $this->ta($org, $training, 'current');

        $this->actingAs($manager)
            ->getJson(route('compliance.not-required-users', $training))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => [['user_id', 'name', 'status']], 'meta']);
    }

    public function test_non_manager_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfView')->create();

        $this->actingAs($member)->get(route('compliance.page'))->assertForbidden();
        $this->actingAs($member)->getJson(route('compliance.by-training'))->assertForbidden();
        $this->actingAs($member)->getJson(route('compliance.by-requirement'))->assertForbidden();
        $this->actingAs($member)->getJson(route('compliance.not-required'))->assertForbidden();
    }

    public function test_rollups_are_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        $tA = Training::factory()->for($orgA, 'organization')->create();
        $tB = Training::factory()->for($orgB, 'organization')->create();
        $this->ta($orgA, $tA, 'overdue');
        $this->ta($orgB, $tB, 'overdue');

        $this->actingAs($managerA)
            ->getJson(route('compliance.by-training'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tA->id);
    }
}
