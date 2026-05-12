<?php

namespace Tests\Feature\Tenancy;

use App\Events\AssignmentCreated;
use App\Events\AssignmentDeleted;
use App\Events\AssignmentUpdated;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\StdFrequency;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssignmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_list_assignments(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create();

        $this->actingAs($admin)
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_manager_can_list_assignments(): void
    {
        // Phase 13.1 broadened the AssignmentPolicy: Manager can viewAny
        // + create to drive the tag-bulk-assignment flow. Update + delete
        // remain Owner/SA/Admin.
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        Assignment::factory()
            ->for($org, 'organization')
            ->for($user, 'user')
            ->for($req, 'requirement')
            ->create();

        $this->actingAs($manager)
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_selfedit_cannot_list_assignments(): void
    {
        // Sanity guard: the policy widening stops at Manager. SelfEdit /
        // SelfView / None still 403 until Phase 12.3 (self-view).
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($self)
            ->getJson('/api/assignments')
            ->assertForbidden();
    }

    public function test_manager_can_create_but_not_update_or_delete(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $created = $this->actingAs($manager)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated()
            ->json();

        // Update is still admin-only.
        $this->actingAs($manager)
            ->patchJson("/api/assignments/{$created['id']}", [
                'name' => 'Renamed',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertForbidden();

        $this->actingAs($manager)
            ->deleteJson("/api/assignments/{$created['id']}")
            ->assertForbidden();
    }

    public function test_list_filters_by_user_id(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $userA = User::factory()->for($org, 'organization')->create();
        $userB = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        Assignment::factory()->for($org, 'organization')->for($userA, 'user')->for($req, 'requirement')->create();
        Assignment::factory()->for($org, 'organization')->for($userB, 'user')->for($req, 'requirement')->create();

        $this->actingAs($admin)
            ->getJson('/api/assignments?user_id='.$userA->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.user_id', $userA->id);
    }

    public function test_list_filters_by_requirement_id(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();
        Assignment::factory()->for($org, 'organization')->for($user, 'user')->for($reqA, 'requirement')->create();
        Assignment::factory()->for($org, 'organization')->for($user, 'user')->for($reqB, 'requirement')->create();

        $this->actingAs($admin)
            ->getJson('/api/assignments?requirement_id='.$reqA->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.requirement_id', $reqA->id);
    }

    public function test_list_does_not_leak_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();
        Assignment::factory()->for($orgB, 'organization')->for($userB, 'user')->for($reqB, 'requirement')->create();

        $this->actingAs($adminA)
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_admin_can_create_assignment(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'Annual Forklift',
                'description' => 'OSHA',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
                'start_date' => '2026-05-12',
                'end_date' => null,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('assignments', [
            'org_id' => $org->id,
            'user_id' => $member->id,
            'requirement_id' => $req->id,
            'name' => 'Annual Forklift',
            'repeating' => true,
        ]);
    }

    public function test_selfedit_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $self = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($self)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertForbidden();
    }

    public function test_create_rejects_cross_org_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqA = Requirement::factory()->for($orgA, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson('/api/assignments', [
                'user_id' => $userB->id,
                'requirement_id' => $reqA->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_create_rejects_cross_org_requirement(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson('/api/assignments', [
                'user_id' => $userA->id,
                'requirement_id' => $reqB->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requirement_id');
    }

    public function test_create_rejects_no_timing_flag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_initial_and_repeating_mutex(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_repeating_without_freq(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_end_before_start(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
                'end_date' => '2026-05-11',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_admin_can_update_assignment(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $assignment = Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create(['name' => 'Old', 'initial_only' => false, 'repeating' => false, 'as_needed' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/assignments/{$assignment->id}", [
                'name' => 'Renamed',
                'description' => 'updated',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
                'end_date' => '2026-06-12',
            ])
            ->assertOk();

        $assignment->refresh();
        $this->assertSame('Renamed', $assignment->name);
        $this->assertTrue($assignment->initial_only);
        $this->assertSame('2026-06-12', $assignment->end_date->toDateString());
    }

    public function test_cross_org_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();
        $assignmentB = Assignment::factory()
            ->for($orgB, 'organization')
            ->for($userB, 'user')
            ->for($reqB, 'requirement')
            ->create();

        $this->actingAs($adminA)
            ->patchJson("/api/assignments/{$assignmentB->id}", [
                'name' => 'hacked',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_soft_delete(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $assignment = Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create();

        $this->actingAs($admin)
            ->deleteJson("/api/assignments/{$assignment->id}")
            ->assertOk();

        $this->assertSoftDeleted('assignments', ['id' => $assignment->id]);
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([AssignmentCreated::class, AssignmentUpdated::class, AssignmentDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $created = $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
                'start_date' => '2026-05-12',
            ])
            ->json();
        $this->actingAs($admin)->patchJson("/api/assignments/{$created['id']}", [
            'name' => 'Y',
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
            'start_date' => '2026-05-12',
        ]);
        $this->actingAs($admin)->deleteJson("/api/assignments/{$created['id']}");

        Event::assertDispatched(AssignmentCreated::class);
        Event::assertDispatched(AssignmentUpdated::class);
        Event::assertDispatched(AssignmentDeleted::class);
    }
}
