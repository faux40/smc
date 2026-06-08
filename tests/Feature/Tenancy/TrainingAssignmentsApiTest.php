<?php

namespace Tests\Feature\Tenancy;

use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentDeleted;
use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TrainingAssignmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ------------------------------------------------------------------
    // index
    // ------------------------------------------------------------------

    public function test_admin_can_list_training_assignments(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);
        AssignmentSource::factory()->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($admin)
            ->getJson('/api/training-assignments')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_filters_by_user_id(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $u1->id, 'training_id' => $t1->id]);
        TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $u2->id, 'training_id' => $t2->id]);

        $this->actingAs($admin)
            ->getJson("/api/training-assignments?user_id={$u1->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['user_id' => $u1->id]);
    }

    public function test_list_filters_by_training_id(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();

        TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $u1->id, 'training_id' => $t1->id]);
        TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $u2->id, 'training_id' => $t2->id]);

        $this->actingAs($admin)
            ->getJson("/api/training-assignments?training_id={$t2->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['training_id' => $t2->id]);
    }

    public function test_list_excludes_other_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $other = User::factory()->for($orgB, 'organization')->create();
        $training = Training::factory()->for($orgB, 'organization')->create();

        // TA belongs to orgB — should not appear for orgA admin
        TrainingAssignment::factory()->create([
            'org_id' => $orgB->id,
            'user_id' => $other->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/training-assignments')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/training-assignments')->assertUnauthorized();
        $this->postJson('/api/training-assignments', [])->assertUnauthorized();
    }

    public function test_selfedit_cannot_list(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();

        $this->actingAs($user)
            ->getJson('/api/training-assignments')
            ->assertForbidden();
    }

    public function test_response_includes_active_sources(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);
        // Active requirement source
        AssignmentSource::factory()->create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
        ]);
        // Removed direct source (should NOT appear)
        AssignmentSource::factory()->removed()->create([
            'training_assignment_id' => $ta->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/training-assignments')
            ->assertOk()
            ->assertJsonCount(1);

        $item = $response->json('0');
        $this->assertCount(1, $item['active_sources']);
        $this->assertSame($req->id, $item['active_sources'][0]['sourceable_id']);
    }

    // ------------------------------------------------------------------
    // store — direct assignment
    // ------------------------------------------------------------------

    public function test_admin_can_create_direct_training_assignment(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $member->id,
                'training_id' => $training->id,
            ])
            ->assertCreated()
            ->assertJsonFragment([
                'user_id' => $member->id,
                'training_id' => $training->id,
            ]);

        $this->assertDatabaseHas('training_assignments', [
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);
        $this->assertDatabaseHas('assignment_sources', [
            'sourceable_type' => null,
            'sourceable_id' => null,
            'removed_at' => null,
        ]);

        Event::assertDispatched(TrainingAssignmentCreated::class);
    }

    public function test_direct_assignment_is_idempotent_on_ta_row(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        // First assignment
        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'source_type' => 'direct',
            'user_id' => $member->id,
            'training_id' => $training->id,
        ])->assertCreated();

        // Second direct assignment for same (user, training) — same TA row, new source
        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'source_type' => 'direct',
            'user_id' => $member->id,
            'training_id' => $training->id,
        ])->assertCreated();

        $this->assertDatabaseCount('training_assignments', 1);
        $this->assertDatabaseCount('assignment_sources', 2);
    }

    public function test_manager_can_create_training_assignment(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $member->id,
                'training_id' => $training->id,
            ])
            ->assertCreated();
    }

    public function test_selfedit_cannot_create_training_assignment(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($user)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $user->id,
                'training_id' => $training->id,
            ])
            ->assertForbidden();
    }

    public function test_store_validates_required_fields(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_type', 'user_id']);
    }

    public function test_store_rejects_cross_org_user(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $otherUser = User::factory()->for($orgB, 'organization')->create();
        $training = Training::factory()->for($orgA, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'direct',
                'user_id' => $otherUser->id,
                'training_id' => $training->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    // ------------------------------------------------------------------
    // store — from-requirement
    // ------------------------------------------------------------------

    public function test_admin_can_assign_from_requirement(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();

        RqmtElement::factory()->for($org, 'organization')->for($req, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $t1->id,
            'name' => $t1->name,
        ]);
        RqmtElement::factory()->for($org, 'organization')->for($req, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $t2->id,
            'name' => $t2->name,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'requirement',
                'user_id' => $member->id,
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        // Returns an array of TAs (one per training element)
        $this->assertCount(2, $response->json());

        $this->assertDatabaseCount('training_assignments', 2);
        $this->assertDatabaseCount('assignment_sources', 2);

        // Each source points to the requirement
        $this->assertDatabaseHas('assignment_sources', [
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'removed_at' => null,
        ]);

        Event::assertDispatched(TrainingAssignmentCreated::class, 2);
    }

    public function test_assign_from_requirement_skips_soft_deleted_elements(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();

        RqmtElement::factory()->for($org, 'organization')->for($req, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $t1->id,
        ]);
        $deletedElement = RqmtElement::factory()->for($org, 'organization')->for($req, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $t2->id,
        ]);
        $deletedElement->delete();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'requirement',
                'user_id' => $member->id,
                'requirement_id' => $req->id,
            ])
            ->assertCreated();

        // Only 1 TA — the deleted element is excluded
        $this->assertDatabaseCount('training_assignments', 1);
    }

    public function test_assign_from_requirement_is_idempotent_on_ta_row(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        RqmtElement::factory()->for($org, 'organization')->for($req, 'requirement')->create([
            'module_type' => Training::class,
            'module_id' => $training->id,
        ]);

        // Assign twice from same requirement
        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'source_type' => 'requirement',
            'user_id' => $member->id,
            'requirement_id' => $req->id,
        ])->assertCreated();

        $this->actingAs($admin)->postJson('/api/training-assignments', [
            'source_type' => 'requirement',
            'user_id' => $member->id,
            'requirement_id' => $req->id,
        ])->assertCreated();

        // Still only one TA row — two source rows
        $this->assertDatabaseCount('training_assignments', 1);
        $this->assertDatabaseCount('assignment_sources', 2);
    }

    public function test_store_rejects_invalid_source_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/training-assignments', [
                'source_type' => 'unknown',
                'user_id' => $member->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_type');
    }

    // ------------------------------------------------------------------
    // destroy
    // ------------------------------------------------------------------

    public function test_admin_can_destroy_training_assignment(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);
        AssignmentSource::factory()->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('training_assignments', ['id' => $ta->id]);
        Event::assertDispatched(TrainingAssignmentDeleted::class);
    }

    public function test_manager_cannot_destroy_training_assignment(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/training-assignments/{$ta->id}")
            ->assertForbidden();
    }

    public function test_destroy_rejects_cross_org_assignment(): void
    {
        // BelongsToOrganization::resolveRouteBinding returns null for a
        // cross-org id → 404 (defense-in-depth, intentional design).
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $otherUser = User::factory()->for($orgB, 'organization')->create();
        $training = Training::factory()->for($orgB, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $orgB->id,
            'user_id' => $otherUser->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}")
            ->assertNotFound();
    }

    public function test_destroy_broadcasts_correct_payload(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/training-assignments/{$ta->id}");

        Event::assertDispatched(TrainingAssignmentDeleted::class, function (TrainingAssignmentDeleted $e) use ($ta) {
            return $e->trainingAssignmentId === $ta->id
                && $e->userId === $ta->user_id
                && $e->orgId === $ta->org_id;
        });
    }

    // -- destroyByRequirement -------------------------------------------------

    public function test_destroy_by_requirement_removes_orphaned_training_assignments(): void
    {
        Event::fake([TrainingAssignmentDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);
        AssignmentSource::factory()->forRequirement($req)->create([
            'training_assignment_id' => $ta->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $user->id,
                'requirement_id' => $req->id,
            ])
            ->assertOk()
            ->assertJsonPath('deleted_ids.0', $ta->id);

        $this->assertDatabaseMissing('training_assignments', ['id' => $ta->id]);
        Event::assertDispatched(TrainingAssignmentDeleted::class,
            fn (TrainingAssignmentDeleted $e) => $e->trainingAssignmentId === $ta->id,
        );
    }

    public function test_destroy_by_requirement_keeps_ta_when_another_source_covers_it(): void
    {
        Event::fake([TrainingAssignmentDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);
        AssignmentSource::factory()->forRequirement($reqA)->create(['training_assignment_id' => $ta->id]);
        AssignmentSource::factory()->forRequirement($reqB)->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($admin)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $user->id,
                'requirement_id' => $reqA->id,
            ])
            ->assertOk()
            ->assertJsonPath('deleted_ids', []);

        $this->assertDatabaseHas('training_assignments', ['id' => $ta->id]);
        $this->assertDatabaseMissing('assignment_sources', [
            'training_assignment_id' => $ta->id,
            'sourceable_id' => $reqA->id,
        ]);
        $this->assertDatabaseHas('assignment_sources', [
            'training_assignment_id' => $ta->id,
            'sourceable_id' => $reqB->id,
        ]);
        Event::assertNotDispatched(TrainingAssignmentDeleted::class);
    }

    public function test_destroy_by_requirement_manager_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $user->id,
                'requirement_id' => $req->id,
            ])
            ->assertForbidden();
    }

    public function test_destroy_by_requirement_cross_org_user_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $reqA = Requirement::factory()->for($orgA, 'organization')->create();

        $this->actingAs($adminA)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $userB->id,
                'requirement_id' => $reqA->id,
            ])
            ->assertUnprocessable();
    }
}
