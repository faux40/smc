<?php

namespace Tests\Feature\Tenancy;

use App\Events\RqmtElementCreated;
use App\Events\RqmtElementDeleted;
use App\Events\RqmtElementUpdated;
use App\Events\TrainingAssignmentCreated;
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

class RqmtElementsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_candidates_returns_elements_matching_module(): void
    {
        // The Phase 10.2 "candidate elements" endpoint: given a module
        // identity, list every rqmt_element in the org that points at it.
        // Drives the manual Completion form's element multi-select.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $otherTraining = Training::factory()->for($org, 'organization')->create();

        // Two elements pointing at $training — one per requirement.
        RqmtElement::factory()
            ->for($org, 'organization')->for($reqA, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id, 'name' => 'A'])
            ->create();
        RqmtElement::factory()
            ->for($org, 'organization')->for($reqB, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id, 'name' => 'B'])
            ->create();

        // One element pointing at a different module — must not appear.
        RqmtElement::factory()
            ->for($org, 'organization')->for($reqA, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $otherTraining->id, 'name' => 'C'])
            ->create();

        $rows = $this->actingAs($admin)
            ->getJson('/api/rqmt-elements/candidates?module_type='.urlencode(Training::class)."&module_id={$training->id}")
            ->assertOk()
            ->assertJsonCount(2)
            ->json();

        $this->assertEqualsCanonicalizing(['A', 'B'], collect($rows)->pluck('name')->all());
        // Each row carries the parent requirement name (joined in the controller).
        $this->assertNotNull($rows[0]['requirement_name']);
    }

    public function test_candidates_does_not_leak_cross_org_elements(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();
        RqmtElement::factory()
            ->for($orgB, 'organization')->for($reqB, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $trainingB->id])
            ->create();

        $this->actingAs($adminA)
            ->getJson('/api/rqmt-elements/candidates?module_type='.urlencode(Training::class)."&module_id={$trainingB->id}")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_candidates_rejects_unknown_module_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();

        $this->actingAs($admin)
            ->getJson('/api/rqmt-elements/candidates?module_type=App%5CModels%5COrganization&module_id=abc')
            ->assertStatus(422);
    }

    public function test_anyone_in_org_can_list_elements(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $this->actingAs($member)
            ->getJson("/api/requirements/{$req->id}/elements")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_cross_org_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();

        $this->actingAs($userA)
            ->getJson("/api/requirements/{$reqB->id}/elements")
            ->assertNotFound();
    }

    public function test_admin_can_create_element_with_training_module(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'Forklift refresh (annual)',
                'description' => 'OSHA 1910.178(l)(4)(iii)',
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('rqmt_elements', [
            'requirement_id' => $req->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'name' => 'Forklift refresh (annual)',
            'repeating' => true,
        ]);
    }

    public function test_create_rejects_duplicate_module_in_requirement(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        // Training is already bound to this requirement.
        RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'Dup attempt',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('module_id');
    }

    public function test_same_module_allowed_in_a_different_requirement(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $reqA = Requirement::factory()->for($org, 'organization')->create();
        $reqB = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        RqmtElement::factory()
            ->for($org, 'organization')
            ->for($reqA, 'requirement')
            ->state(['module_type' => Training::class, 'module_id' => $training->id])
            ->create();

        // Same training in a *different* requirement is fine.
        $this->actingAs($admin)
            ->postJson("/api/requirements/{$reqB->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'Same training, other requirement',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertCreated();
    }

    public function test_manager_cannot_create(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertForbidden();
    }

    public function test_create_rejects_no_timing_flag(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'X',
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_initial_and_repeating_mutex(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => true,
                'std_freq_id' => $freq->id,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_repeating_without_freq(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'X',
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_cross_org_module(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $reqA = Requirement::factory()->for($orgA, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->postJson("/api/requirements/{$reqA->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $trainingB->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_create_rejects_unknown_module_type(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => 'App\\Models\\Organization',
                'module_id' => $org->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertStatus(422);
    }

    public function test_admin_can_update_element(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create(['name' => 'Old', 'as_needed' => true, 'repeating' => false, 'initial_only' => false]);

        $this->actingAs($admin)
            ->patchJson("/api/rqmt-elements/{$element->id}", [
                'name' => 'Renamed',
                'description' => 'updated',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertOk();

        $element->refresh();
        $this->assertSame('Renamed', $element->name);
        $this->assertTrue($element->initial_only);
    }

    public function test_admin_can_delete_element(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $element = RqmtElement::factory()
            ->for($org, 'organization')
            ->for($req, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
            ])
            ->create();

        $this->actingAs($admin)
            ->deleteJson("/api/rqmt-elements/{$element->id}")
            ->assertOk();

        $this->assertSoftDeleted('rqmt_elements', ['id' => $element->id]);
    }

    public function test_cross_org_element_update_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $reqB = Requirement::factory()->for($orgB, 'organization')->create();
        $trainingB = Training::factory()->for($orgB, 'organization')->create();
        $elementB = RqmtElement::factory()
            ->for($orgB, 'organization')
            ->for($reqB, 'requirement')
            ->state([
                'module_type' => Training::class,
                'module_id' => $trainingB->id,
            ])
            ->create();

        $this->actingAs($adminA)
            ->patchJson("/api/rqmt-elements/{$elementB->id}", [
                'name' => 'hacked',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->assertNotFound();
    }

    public function test_create_update_delete_broadcast(): void
    {
        Event::fake([RqmtElementCreated::class, RqmtElementUpdated::class, RqmtElementDeleted::class]);

        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $created = $this->actingAs($admin)
            ->postJson("/api/requirements/{$req->id}/elements", [
                'module_type' => Training::class,
                'module_id' => $training->id,
                'name' => 'X',
                'initial_only' => true,
                'repeating' => false,
                'as_needed' => false,
            ])
            ->json();
        $this->actingAs($admin)->patchJson("/api/rqmt-elements/{$created['id']}", [
            'name' => 'Y',
            'initial_only' => true,
            'repeating' => false,
            'as_needed' => false,
        ]);
        $this->actingAs($admin)->deleteJson("/api/rqmt-elements/{$created['id']}");

        Event::assertDispatched(RqmtElementCreated::class);
        Event::assertDispatched(RqmtElementUpdated::class);
        Event::assertDispatched(RqmtElementDeleted::class);
    }

    /**
     * Scenario for the J2 recalc triggers: training template repeats every
     * 365 days, the requirement's element every 90; the user's TA is sourced
     * by the requirement only and was completed 2026-01-01, so its expiry
     * follows the element cycle (2026-04-01) until the element changes.
     *
     * @return array{admin: User, ta: TrainingAssignment, element: RqmtElement, freq365: StdFrequency}
     */
    private function makeRecalcScenario(): array
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
        $element = RqmtElement::factory()
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

        return compact('admin', 'ta', 'element', 'freq365');
    }

    public function test_updating_element_timing_recalculates_affected_assignments(): void
    {
        ['admin' => $admin, 'ta' => $ta, 'element' => $element, 'freq365' => $freq365]
            = $this->makeRecalcScenario();

        Event::fake([TrainingAssignmentCreated::class]);

        $this->actingAs($admin)
            ->patchJson("/api/rqmt-elements/{$element->id}", [
                'name' => $element->name,
                'initial_only' => false,
                'repeating' => true,
                'std_freq_id' => $freq365->id,
                'as_needed' => false,
            ])
            ->assertOk();

        // Element cycle loosened 90 → 365 days; the TA follows and the change
        // is broadcast so open tabs refresh the pill.
        $this->assertEquals('2027-01-01', $ta->refresh()->expires_at->toDateString());
        Event::assertDispatched(
            TrainingAssignmentCreated::class,
            fn ($e) => $e->trainingAssignment->id === $ta->id,
        );
    }

    public function test_deleting_element_recalculates_affected_assignments(): void
    {
        ['admin' => $admin, 'ta' => $ta, 'element' => $element] = $this->makeRecalcScenario();

        Event::fake([TrainingAssignmentCreated::class]);

        $this->actingAs($admin)
            ->deleteJson("/api/rqmt-elements/{$element->id}")
            ->assertOk();

        // Element gone → the requirement source falls back to the training
        // template's 365-day cycle.
        $this->assertEquals('2027-01-01', $ta->refresh()->expires_at->toDateString());
        Event::assertDispatched(
            TrainingAssignmentCreated::class,
            fn ($e) => $e->trainingAssignment->id === $ta->id,
        );
    }
}
