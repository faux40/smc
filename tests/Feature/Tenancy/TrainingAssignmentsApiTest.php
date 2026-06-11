<?php

namespace Tests\Feature\Tenancy;

use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentDeleted;
use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\StdFrequency;
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

    public function test_index_rows_include_canonical_status_and_days(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $member->id,
            'training_id' => $training->id,
            'last_completed_at' => now()->subYear(),
            'expires_at' => now()->subDays(10),
        ]);
        AssignmentSource::factory()->create(['training_assignment_id' => $ta->id]);

        $row = $this->actingAs($admin)
            ->getJson('/api/training-assignments')
            ->assertOk()
            ->json('0');

        $this->assertSame('overdue', $row['status']);
        $this->assertSame(-10, $row['days_until_due']);
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

    // -- breakFromRequirement -------------------------------------------------

    public function test_break_from_requirement_deletes_ta_when_single_source(): void
    {
        Event::fake();

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
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertOk()
            ->assertJsonPath('deleted_id', $ta->id)
            ->assertJsonPath('updated_ids', []);

        $this->assertDatabaseMissing('training_assignments', ['id' => $ta->id]);
    }

    public function test_break_from_requirement_converts_siblings_to_direct_sources(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();
        $t3 = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $ta1 = TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'training_id' => $t1->id]);
        $ta2 = TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'training_id' => $t2->id]);
        $ta3 = TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'training_id' => $t3->id]);

        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta1->id]);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta2->id]);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta3->id]);

        $response = $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta1->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertOk();

        // Target deleted, siblings in updated_ids
        $this->assertEquals($ta1->id, $response->json('deleted_id'));
        $this->assertEqualsCanonicalizing([$ta2->id, $ta3->id], $response->json('updated_ids'));

        // Target TA is gone
        $this->assertDatabaseMissing('training_assignments', ['id' => $ta1->id]);

        // Siblings still exist
        $this->assertDatabaseHas('training_assignments', ['id' => $ta2->id]);
        $this->assertDatabaseHas('training_assignments', ['id' => $ta3->id]);

        // Siblings no longer have the requirement source
        $this->assertDatabaseMissing('assignment_sources', [
            'training_assignment_id' => $ta2->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
        ]);
        $this->assertDatabaseMissing('assignment_sources', [
            'training_assignment_id' => $ta3->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
        ]);

        // Siblings each have a new direct source
        $this->assertDatabaseHas('assignment_sources', [
            'training_assignment_id' => $ta2->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
        ]);
        $this->assertDatabaseHas('assignment_sources', [
            'training_assignment_id' => $ta3->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
        ]);
    }

    public function test_break_from_requirement_keeps_ta_when_another_source_remains(): void
    {
        Event::fake();

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
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta->id]);
        AssignmentSource::factory()->create(['training_assignment_id' => $ta->id]); // direct source

        $response = $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertOk();

        $this->assertNull($response->json('deleted_id'));
        $this->assertContains($ta->id, $response->json('updated_ids'));

        // TA still exists
        $this->assertDatabaseHas('training_assignments', ['id' => $ta->id]);

        // Requirement source removed
        $this->assertDatabaseMissing('assignment_sources', [
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
        ]);

        // Direct source still present
        $this->assertDatabaseHas('assignment_sources', [
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
        ]);
    }

    public function test_break_from_requirement_fires_correct_events(): void
    {
        Event::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $ta1 = TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'training_id' => $t1->id]);
        $ta2 = TrainingAssignment::factory()->create(['org_id' => $org->id, 'user_id' => $user->id, 'training_id' => $t2->id]);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta1->id]);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta2->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta1->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertOk();

        // Deleted event for the removed TA
        Event::assertDispatched(TrainingAssignmentDeleted::class,
            fn (TrainingAssignmentDeleted $e) => $e->trainingAssignmentId === $ta1->id,
        );

        // Created event for each converted sibling (peer-tab source sync)
        Event::assertDispatched(TrainingAssignmentCreated::class,
            fn (TrainingAssignmentCreated $e) => $e->trainingAssignment->id === $ta2->id,
        );
    }

    public function test_break_from_requirement_returns_422_when_source_not_found(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $otherReq = Requirement::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);
        // TA is sourced by $req, not $otherReq
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $otherReq->id,
            ])
            ->assertUnprocessable();
    }

    public function test_break_from_requirement_manager_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertForbidden();
    }

    public function test_break_from_requirement_cross_org_ta_is_not_found(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $admin = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $training = Training::factory()->for($orgB, 'organization')->create();
        $req = Requirement::factory()->for($orgA, 'organization')->create();

        $ta = TrainingAssignment::factory()->create([
            'org_id' => $orgB->id,
            'user_id' => $userB->id,
            'training_id' => $training->id,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertNotFound();
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

    // ------------------------------------------------------------------
    // J2 — source removal recalculates surviving TAs
    // ------------------------------------------------------------------

    /**
     * Direct + requirement sources on one TA: template 365d, element 90d,
     * completed 2026-01-01 → expiry follows the stricter element (2026-04-01)
     * until the requirement source goes away.
     *
     * @return array{admin: User, user: User, ta: TrainingAssignment, req: Requirement}
     */
    private function makeSourceRemovalScenario(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $user = User::factory()->for($org, 'organization')->create();
        $freq365 = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 365]);
        $freq90 = StdFrequency::factory()->for($org, 'organization')->create(['repeat_days' => 90]);
        $training = Training::factory()->for($org, 'organization')->create([
            'repeating' => true,
            'std_freq_id' => $freq365->id,
        ]);
        $req = Requirement::factory()->for($org, 'organization')->create();
        RqmtElement::factory()
            ->for($org, 'organization')->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq90->id,
                'as_needed' => false,
            ])
            ->create();
        $ta = TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => null,
            'sourceable_id' => null,
            'added_at' => now(),
        ]);
        AssignmentSource::create([
            'training_assignment_id' => $ta->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);
        Completion::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        $this->assertEquals('2026-04-01', $ta->refresh()->expires_at->toDateString());

        return compact('admin', 'user', 'ta', 'req');
    }

    public function test_break_from_requirement_recalculates_surviving_ta(): void
    {
        ['admin' => $admin, 'ta' => $ta, 'req' => $req] = $this->makeSourceRemovalScenario();

        $this->actingAs($admin)
            ->deleteJson("/api/training-assignments/{$ta->id}/from-requirement", [
                'requirement_id' => $req->id,
            ])
            ->assertOk()
            ->assertJsonPath('deleted_id', null);

        // Only the direct source remains → expiry loosens to the template.
        $this->assertEquals('2027-01-01', $ta->refresh()->expires_at->toDateString());
    }

    public function test_destroy_by_requirement_recalculates_surviving_ta(): void
    {
        ['admin' => $admin, 'user' => $user, 'ta' => $ta, 'req' => $req]
            = $this->makeSourceRemovalScenario();

        $this->actingAs($admin)
            ->deleteJson('/api/training-assignments/by-requirement', [
                'user_id' => $user->id,
                'requirement_id' => $req->id,
            ])
            ->assertOk()
            ->assertJsonPath('updated_ids.0', $ta->id);

        $this->assertEquals('2027-01-01', $ta->refresh()->expires_at->toDateString());
    }
}
