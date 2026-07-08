<?php

namespace Tests\Feature\Tenancy;

use App\Events\TrainingAssignmentCreated;
use App\Events\TrainingAssignmentsBulkChanged;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RqmtElement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        Event::fake([TrainingAssignmentCreated::class, TrainingAssignmentsBulkChanged::class]);

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
        // F4: the bulk path emits one org-channel signal, not one per TA.
        Event::assertNotDispatched(TrainingAssignmentCreated::class);
        Event::assertDispatched(TrainingAssignmentsBulkChanged::class, 1);
        Event::assertDispatched(
            TrainingAssignmentsBulkChanged::class,
            fn (TrainingAssignmentsBulkChanged $e) => $e->orgId === $org->id,
        );
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
        Event::fake([TrainingAssignmentCreated::class, TrainingAssignmentsBulkChanged::class]);

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
        // Still one bulk signal regardless of how many TAs the requirement
        // exploded into.
        Event::assertNotDispatched(TrainingAssignmentCreated::class);
        Event::assertDispatched(TrainingAssignmentsBulkChanged::class, 1);
    }

    public function test_bulk_assign_with_no_valid_users_emits_no_broadcast(): void
    {
        Event::fake([TrainingAssignmentsBulkChanged::class]);

        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$outsider->id],
                'source_type' => 'direct',
                'training_id' => $training->id,
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 0, 'skipped_count' => 1]);

        Event::assertNotDispatched(TrainingAssignmentsBulkChanged::class);
    }

    public function test_bulk_assign_materializes_status_for_every_created_ta(): void
    {
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
            ->assertCreated();

        // No completion history → every fresh TA lands in not_started, never a
        // null bucket (the dashboard reads the materialized status).
        $this->assertSame(0, TrainingAssignment::whereNull('status')->count());
        $this->assertSame(2, TrainingAssignment::where('status', 'not_started')->count());
    }

    public function test_bulk_assign_query_cost_stays_under_a_ceiling(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $t1 = Training::factory()->for($org, 'organization')->create();
        $t2 = Training::factory()->for($org, 'organization')->create();
        $t3 = Training::factory()->for($org, 'organization')->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create();
        foreach ([$t1, $t2, $t3] as $t) {
            RqmtElement::factory()->for($org, 'organization')->for($requirement, 'requirement')
                ->create(['module_type' => Training::class, 'module_id' => $t->id, 'name' => $t->name]);
        }

        // 8 users × a 3-element requirement = 24 TAs. The naive per-iteration
        // recalc (re-fetching training/org every element, per-user requirement
        // reload) ran ~11 queries/TA (~260+); batching keeps the whole request
        // well under this ceiling — the loop-invariant lookups are hoisted and
        // reads are collapsed to a couple of whereIns.
        $users = User::factory()->for($org, 'organization')->count(8)->create();

        $this->actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson('/api/bulk-training-assignments', [
            'user_ids' => $users->pluck('id')->all(),
            'source_type' => 'requirement',
            'requirement_id' => $requirement->id,
        ])->assertCreated()->assertJson(['created_count' => 24]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(120, $queries, "Bulk assign ran {$queries} queries; expected < 120.");
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

    public function test_cross_org_training_id_fails_validation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();
        $foreignTraining = Training::factory()->for($otherOrg, 'organization')->create();

        // O1: org-scoped Rule::exists() rejects the foreign id at validation
        // (422) — independent of the service-layer findOrFail guard.
        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u->id],
                'source_type' => 'direct',
                'training_id' => $foreignTraining->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('training_id');

        $this->assertDatabaseCount('training_assignments', 0);
    }

    public function test_cross_org_requirement_id_fails_validation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();
        $foreignReq = Requirement::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/bulk-training-assignments', [
                'user_ids' => [$u->id],
                'source_type' => 'requirement',
                'requirement_id' => $foreignReq->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirement_id');

        $this->assertDatabaseCount('training_assignments', 0);
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
