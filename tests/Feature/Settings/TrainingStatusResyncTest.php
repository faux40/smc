<?php

namespace Tests\Feature\Settings;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingStatusResyncTest extends TestCase
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
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        return [$org, $admin, $user, $training];
    }

    public function test_admin_can_trigger_resync(): void
    {
        [, $admin] = $this->scaffoldOrg();

        $this->actingAs($admin)
            ->postJson('/api/settings/training-status-resync')
            ->assertOk()
            ->assertJsonStructure(['processed']);
    }

    public function test_owner_can_trigger_resync(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->withRole('Owner')->create();

        $this->actingAs($owner)
            ->postJson('/api/settings/training-status-resync')
            ->assertOk();
    }

    public function test_self_edit_gets_403(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->postJson('/api/settings/training-status-resync')
            ->assertForbidden();
    }

    public function test_manager_gets_403(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->postJson('/api/settings/training-status-resync')
            ->assertForbidden();
    }

    public function test_guest_gets_401(): void
    {
        $this->postJson('/api/settings/training-status-resync')
            ->assertUnauthorized();
    }

    public function test_resync_sets_expires_at_from_completion_history(): void
    {
        [$org, $admin, $user, $training] = $this->scaffoldOrg();

        $ta = TrainingAssignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($training, 'training')
            ->create(['expires_at' => null]);

        // A completion with an explicit expire_date.
        Completion::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => now()->subMonths(3)->toDateString(),
                'expire_date' => now()->addDays(20)->toDateString(),
            ])
            ->create();

        $this->actingAs($admin)
            ->postJson('/api/settings/training-status-resync')
            ->assertOk()
            ->assertJson(['processed' => 1]);

        $ta->refresh();
        $this->assertNotNull($ta->expires_at);
        $this->assertSame(now()->addDays(20)->toDateString(), $ta->expires_at->toDateString());
    }

    public function test_resync_is_org_scoped(): void
    {
        // Org A has no training assignments. Org B has one.
        // Org A admin runs resync — processed count must be 0 (only org A pairs
        // are iterated; org B's records are untouched by design).
        [, $adminA] = $this->scaffoldOrg();
        [$orgB, , $userB, $trainingB] = $this->scaffoldOrg();

        TrainingAssignment::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->for($trainingB, 'training')
            ->create();

        $this->actingAs($adminA)
            ->postJson('/api/settings/training-status-resync')
            ->assertOk()
            ->assertJson(['processed' => 0]);
    }

    public function test_resync_returns_processed_count(): void
    {
        [$org, $admin, $user, $training] = $this->scaffoldOrg();
        $trainingB = Training::factory()->for($org, 'organization')->create();
        $trainingC = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()->for($org, 'organization')->for($user, 'user')->for($training, 'training')->create();
        TrainingAssignment::factory()->for($org, 'organization')->for($user, 'user')->for($trainingB, 'training')->create();
        TrainingAssignment::factory()->for($org, 'organization')->for($user, 'user')->for($trainingC, 'training')->create();

        $this->actingAs($admin)
            ->postJson('/api/settings/training-status-resync')
            ->assertOk()
            ->assertJson(['processed' => 3]);
    }
}
