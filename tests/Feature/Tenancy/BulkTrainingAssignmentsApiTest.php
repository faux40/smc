<?php

namespace Tests\Feature\Tenancy;

use App\Events\TrainingAssignmentCreated;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BulkTrainingAssignmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ------------------------------------------------------------------
    // Authorization
    // ------------------------------------------------------------------

    public function test_guest_cannot_bulk_assign(): void
    {
        $this->postJson('/api/bulk-training-assignments', [])
            ->assertUnauthorized();
    }

    public function test_regular_member_cannot_bulk_assign(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfView')->create();
        $t = Training::factory()->for($org, 'organization')->create();
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u->id],
                'source_type' => 'direct',
                'training_id' => $t->id,
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Direct assignment
    // ------------------------------------------------------------------

    public function test_admin_can_bulk_assign_direct_to_multiple_users(): void
    {
        Event::fake([TrainingAssignmentCreated::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u1->id, $u2->id],
                'source_type' => 'direct',
                'training_id' => $training->id,
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 2, 'skipped_count' => 0]);

        $this->assertDatabaseCount('training_assignments', 2);
        Event::assertDispatched(TrainingAssignmentCreated::class, 2);
    }

    public function test_bulk_direct_assign_skips_users_from_other_orgs(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$member->id, $outsider->id],
                'source_type' => 'direct',
                'training_id' => $training->id,
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 1, 'skipped_count' => 1]);

        $this->assertDatabaseCount('training_assignments', 1);
    }

    // ------------------------------------------------------------------
    // Requirement-exploded assignment
    // ------------------------------------------------------------------

    public function test_admin_can_bulk_assign_from_requirement(): void
    {
        Event::fake([TrainingAssignmentCreated::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();

        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create();
        RqmtElement::factory()->for($org, 'organization')->for($requirement, 'requirement')->create(['module_type' => Training::class, 'module_id' => $t1->id, 'name' => $t1->name]);
        RqmtElement::factory()->for($org, 'organization')->for($requirement, 'requirement')->create(['module_type' => Training::class, 'module_id' => $t2->id, 'name' => $t2->name]);

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u1->id, $u2->id],
                'source_type' => 'requirement',
                'requirement_id' => $requirement->id,
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 4, 'skipped_count' => 0]);

        // 2 users × 2 trainings = 4 training_assignment rows.
        $this->assertDatabaseCount('training_assignments', 4);
        Event::assertDispatched(TrainingAssignmentCreated::class, 4);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_training_id_required_for_direct_source_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u->id],
                'source_type' => 'direct',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('training_id');
    }

    public function test_requirement_id_required_for_requirement_source_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u->id],
                'source_type' => 'requirement',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirement_id');
    }

    public function test_user_ids_must_not_be_empty(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $t = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [],
                'source_type' => 'direct',
                'training_id' => $t->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');
    }
}
