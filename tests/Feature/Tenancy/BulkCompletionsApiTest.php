<?php

namespace Tests\Feature\Tenancy;

use App\Events\CompletionCreated;
use App\Events\CompletionsBulkChanged;
use App\Models\Completion;
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

class BulkCompletionsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * A Requirement + one Training-typed element pointing at the training, so
     * the rqmt_element_ids payload has a valid element to credit.
     *
     * @return array{0: Training, 1: RqmtElement}
     */
    private function trainingWithElement(Organization $org): array
    {
        $training = Training::factory()->for($org, 'organization')->create();
        $requirement = Requirement::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($requirement, 'requirement')
            ->create(['module_type' => Training::class, 'module_id' => $training->id, 'name' => $training->name]);

        return [$training, $element];
    }

    // ------------------------------------------------------------------
    // Authorization (same gate as the single store — CompletionPolicy create)
    // ------------------------------------------------------------------

    public function test_guest_cannot_bulk_record(): void
    {
        $this->postJson('/api/completions/bulk', [])->assertUnauthorized();
    }

    public function test_regular_member_cannot_bulk_record(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfView')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertForbidden();
    }

    public function test_manager_can_bulk_record(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 1, 'skipped_count' => 0]);
    }

    // ------------------------------------------------------------------
    // Core behaviour
    // ------------------------------------------------------------------

    public function test_admin_records_one_completion_per_user_with_shared_fields(): void
    {
        Event::fake([CompletionCreated::class, CompletionsBulkChanged::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u1->id, $u2->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'expire_date' => '2027-07-01',
                'cert_ident' => 'TG-42',
                'hours' => 1.5,
                'notes' => 'Tailgate talk',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 2, 'skipped_count' => 0]);

        $this->assertDatabaseCount('completions', 2);

        foreach ([$u1, $u2] as $u) {
            $c = Completion::where('user_id', $u->id)->firstOrFail();
            $this->assertSame($training->id, $c->module_id);
            $this->assertSame(Training::class, $c->module_type);
            $this->assertSame('2026-07-01', $c->completion_date->toDateString());
            $this->assertSame('2027-07-01', $c->expire_date->toDateString());
            $this->assertSame('TG-42', $c->cert_ident);
            $this->assertEqualsWithDelta(1.5, $c->hours, 0.001);
            $this->assertSame('Tailgate talk', $c->notes);
            // The element pivot is synced for every created completion.
            $this->assertEqualsCanonicalizing([$element->id], $c->rqmtElements()->pluck('rqmt_elements.id')->all());
        }

        // F8: one org-channel signal, not one CompletionCreated per record.
        Event::assertNotDispatched(CompletionCreated::class);
        Event::assertDispatched(CompletionsBulkChanged::class, 1);
        Event::assertDispatched(
            CompletionsBulkChanged::class,
            fn (CompletionsBulkChanged $e) => $e->orgId === $org->id,
        );
    }

    public function test_batched_recalc_updates_assignment_status(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u1 = User::factory()->for($org, 'organization')->create();
        $u2 = User::factory()->for($org, 'organization')->create();

        // Pre-existing assignments with no completion history → not_started.
        foreach ([$u1, $u2] as $u) {
            TrainingAssignment::factory()->for($org, 'organization')->create([
                'user_id' => $u->id,
                'training_id' => $training->id,
                'name' => $training->name,
                'status' => 'not_started',
                'last_completed_at' => null,
            ]);
        }

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u1->id, $u2->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated();

        // The single batched handleMany() stamped last_completed_at on both
        // assignments and moved them off not_started.
        foreach ([$u1, $u2] as $u) {
            $ta = TrainingAssignment::where('user_id', $u->id)->where('training_id', $training->id)->firstOrFail();
            $this->assertSame('2026-07-01', $ta->last_completed_at?->toDateString());
            $this->assertNotSame('not_started', $ta->status);
        }
    }

    public function test_records_are_created_even_when_a_completion_already_exists(): void
    {
        // Duplicates are legitimate (a retake) — unlike bulk assignment, an
        // existing completion is NOT a skip.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u = User::factory()->for($org, 'organization')->create();

        Completion::factory()->for($org, 'organization')->create([
            'user_id' => $u->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 1, 'skipped_count' => 0]);

        $this->assertSame(2, Completion::where('user_id', $u->id)->count());
    }

    public function test_skips_users_from_other_orgs(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $member = User::factory()->for($org, 'organization')->create();
        $outsider = User::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$member->id, $outsider->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 1, 'skipped_count' => 1]);

        $this->assertDatabaseCount('completions', 1);
        $this->assertSame(0, Completion::where('user_id', $outsider->id)->count());
    }

    public function test_no_valid_users_emits_no_broadcast(): void
    {
        Event::fake([CompletionsBulkChanged::class]);

        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $outsider = User::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$outsider->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertCreated()
            ->assertJson(['created_count' => 0, 'skipped_count' => 1]);

        $this->assertDatabaseCount('completions', 0);
        Event::assertNotDispatched(CompletionsBulkChanged::class);
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    public function test_user_ids_required(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');
    }

    public function test_training_id_required(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('training_id');
    }

    public function test_cross_org_training_id_fails_validation(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $u = User::factory()->for($org, 'organization')->create();
        $foreignTraining = Training::factory()->for($otherOrg, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $foreignTraining->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('training_id');

        $this->assertDatabaseCount('completions', 0);
    }

    public function test_element_must_point_at_the_selected_training(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training] = $this->trainingWithElement($org);
        // An element for a DIFFERENT training must be rejected.
        [, $otherElement] = $this->trainingWithElement($org);
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $training->id,
                'completion_date' => '2026-07-01',
                'rqmt_element_ids' => [$otherElement->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rqmt_element_ids');

        $this->assertDatabaseCount('completions', 0);
    }

    public function test_completion_date_required(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);
        $u = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson('/api/completions/bulk', [
                'user_ids' => [$u->id],
                'training_id' => $training->id,
                'rqmt_element_ids' => [$element->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('completion_date');
    }

    // ------------------------------------------------------------------
    // Query cost — batched recalc, not N observer round-trips
    // ------------------------------------------------------------------

    public function test_query_cost_stays_under_a_ceiling(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        [$training, $element] = $this->trainingWithElement($org);

        // 12 users each already assigned the training, so the recalc has real
        // work (an assignment to recompute per user). Batched via handleMany,
        // the whole request stays well under this ceiling; a per-completion
        // observer recalc would blow past it.
        $users = User::factory()->for($org, 'organization')->count(12)->create();
        foreach ($users as $u) {
            TrainingAssignment::factory()->for($org, 'organization')->create([
                'user_id' => $u->id,
                'training_id' => $training->id,
                'name' => $training->name,
                'status' => 'not_started',
            ]);
        }

        $this->actingAs($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->postJson('/api/completions/bulk', [
            'user_ids' => $users->pluck('id')->all(),
            'training_id' => $training->id,
            'completion_date' => '2026-07-01',
            'rqmt_element_ids' => [$element->id],
        ])->assertCreated()->assertJson(['created_count' => 12]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(80, $queries, "Bulk completion ran {$queries} queries; expected < 80.");
    }
}
