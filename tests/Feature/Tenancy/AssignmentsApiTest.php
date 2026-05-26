<?php

namespace Tests\Feature\Tenancy;

use App\Events\AssignmentCreated;
use App\Events\AssignmentDeleted;
use App\Events\AssignmentUpdated;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
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

    public function test_list_excludes_expired_assignments(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $base = fn () => Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement');

        // Expired (past end_date) — hidden. Active (null), future end, and
        // ending today — all shown.
        $base()->create(['end_date' => now()->subDay()->toDateString()]);
        $base()->create(['end_date' => null]);
        $base()->create(['end_date' => now()->addWeek()->toDateString()]);
        $base()->create(['end_date' => now()->toDateString()]);

        $this->actingAs($admin)
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonCount(3);
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
                'start_date' => '2026-05-12',
            ])
            ->assertCreated()
            ->json();

        // Update is still admin-only.
        $this->actingAs($manager)
            ->patchJson("/api/assignments/{$created['id']}", [
                'name' => 'Renamed',
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
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Annual Forklift']);

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'description' => 'OSHA',
                'start_date' => '2026-05-12',
                'end_date' => null,
            ])
            ->assertCreated();

        // Name is copied from the requirement server-side (client omits it).
        $this->assertDatabaseHas('assignments', [
            'org_id' => $org->id,
            'user_id' => $member->id,
            'requirement_id' => $req->id,
            'name' => 'Annual Forklift',
        ]);
    }

    public function test_list_includes_element_timing_summary(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        // Two repeating elements + one as-needed on the requirement. Each
        // binds a distinct training (a requirement can't list one twice).
        foreach ([['repeating' => true], ['repeating' => true], ['as_needed' => true]] as $attrs) {
            $training = Training::factory()->for($org, 'organization')->create();
            RqmtElement::factory()
                ->for($org, 'organization')
                ->for($req, 'requirement')
                ->state(array_merge([
                    'module_type' => Training::class,
                    'module_id' => $training->id,
                    'initial_only' => false,
                    'repeating' => false,
                    'as_needed' => false,
                ], $attrs))
                ->create();
        }

        Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create();

        $this->actingAs($admin)
            ->getJson('/api/assignments')
            ->assertOk()
            ->assertJsonPath('0.element_timing.repeating', 2)
            ->assertJsonPath('0.element_timing.as_needed', 1)
            ->assertJsonPath('0.element_timing.initial', 0)
            ->assertJsonPath('0.element_timing.none', 0);
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
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requirement_id');
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
                'start_date' => '2026-05-12',
                'end_date' => '2026-05-11',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_create_rejects_duplicate_active_assignment(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        // An active assignment already exists for this (user, requirement).
        Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create(['end_date' => null]);

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'start_date' => '2026-05-12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requirement_id');
    }

    public function test_create_allows_reassign_after_prior_ended(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        // A prior assignment that's been ended (end_date set) frees the pair.
        Assignment::factory()
            ->for($org, 'organization')
            ->for($member, 'user')
            ->for($req, 'requirement')
            ->create(['end_date' => '2026-01-01']);

        $this->actingAs($admin)
            ->postJson('/api/assignments', [
                'user_id' => $member->id,
                'requirement_id' => $req->id,
                'start_date' => '2026-05-12',
            ])
            ->assertCreated();
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
            ->create(['name' => 'Old']);

        $this->actingAs($admin)
            ->patchJson("/api/assignments/{$assignment->id}", [
                'description' => 'updated',
                'start_date' => '2026-05-12',
                'end_date' => '2026-06-12',
            ])
            ->assertOk();

        $assignment->refresh();
        // Name is the requirement snapshot — not editable from the form.
        $this->assertSame('Old', $assignment->name);
        $this->assertSame('updated', $assignment->description);
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
                'start_date' => '2026-05-12',
            ])
            ->assertNotFound();
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
                'start_date' => '2026-05-12',
            ])
            ->json();
        $this->actingAs($admin)->patchJson("/api/assignments/{$created['id']}", [
            'name' => 'Y',
            'start_date' => '2026-05-12',
        ]);
        $this->actingAs($admin)->deleteJson("/api/assignments/{$created['id']}");

        Event::assertDispatched(AssignmentCreated::class);
        Event::assertDispatched(AssignmentUpdated::class);
        Event::assertDispatched(AssignmentDeleted::class);
    }
}
