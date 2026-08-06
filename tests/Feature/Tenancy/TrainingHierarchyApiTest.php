<?php

namespace Tests\Feature\Tenancy;

use App\Actions\RecalculateTrainingStatus;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Write side of the training hierarchy: setting `superseded_by_id` through
 * the trainings API. The engine trusts the data, so the API must refuse what
 * the engine merely survives — cross-org pointers, self-reference, cycles.
 * Re-pointing a ladder resyncs the affected assignments immediately: an admin
 * who wires Authorized to Competent expects the compliance page to move now,
 * not at the nightly watchdog.
 */
class TrainingHierarchyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Admin')->create();
    }

    private function training(Organization $org, string $name): Training
    {
        // initial_only, not as_needed: an as-needed-only assignment pins its
        // status to 'as_needed' regardless of credits, which would mask the
        // very transitions these tests assert.
        return Training::factory()->for($org, 'organization')->create([
            'name' => $name,
            'as_needed' => false,
            'repeating' => false,
            'initial_only' => true,
        ]);
    }

    /** The full-form PATCH payload the page sends; hierarchy rides along. */
    private function payload(Training $t, array $over = []): array
    {
        return [
            'name' => $t->name,
            'initial_only' => $t->initial_only,
            'repeating' => $t->repeating,
            'as_needed' => $t->as_needed,
            'std_freq_id' => $t->std_freq_id,
            ...$over,
        ];
    }

    public function test_update_sets_and_returns_the_pointer(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $competent = $this->training($org, 'Competent');

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => $competent->id,
            ]))
            ->assertOk()
            ->assertJsonPath('superseded_by_id', $competent->id);

        $this->assertSame($competent->id, $authorized->fresh()->superseded_by_id);
    }

    public function test_update_clears_the_pointer_with_null(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $authorized->update(['superseded_by_id' => $competent->id]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => null,
            ]))
            ->assertOk();

        $this->assertNull($authorized->fresh()->superseded_by_id);
    }

    public function test_store_accepts_a_pointer(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $competent = $this->training($org, 'Competent');

        $response = $this->actingAs($admin)
            ->postJson('/api/trainings', [
                'name' => 'Authorized',
                'initial_only' => false,
                'repeating' => false,
                'as_needed' => true,
                'superseded_by_id' => $competent->id,
            ])
            ->assertCreated();

        $this->assertSame($competent->id, Training::findOrFail($response->json('id'))->superseded_by_id);
    }

    public function test_a_cross_org_pointer_is_refused(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $foreign = $this->training($otherOrg, 'Foreign Competent');

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => $foreign->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('superseded_by_id');
    }

    public function test_pointing_a_training_at_itself_is_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => $authorized->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('superseded_by_id');
    }

    public function test_a_two_node_cycle_is_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $a = $this->training($org, 'A');
        $b = $this->training($org, 'B');
        $a->update(['superseded_by_id' => $b->id]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$b->id}", $this->payload($b, [
                'superseded_by_id' => $a->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('superseded_by_id');
    }

    public function test_a_deep_cycle_is_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $a = $this->training($org, 'A');
        $b = $this->training($org, 'B');
        $c = $this->training($org, 'C');
        $a->update(['superseded_by_id' => $b->id]);
        $b->update(['superseded_by_id' => $c->id]);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$c->id}", $this->payload($c, [
                'superseded_by_id' => $a->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('superseded_by_id');
    }

    public function test_repointing_resyncs_affected_assignments_immediately(): void
    {
        // The admin wires the ladder AFTER people already hold credentials.
        // The compliance rows must move on save, not at the nightly watchdog.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $freq = StdFrequency::create(['org_id' => $org->id, 'name' => 'Biennial', 'repeat_days' => 730]);
        $competent = Training::factory()->for($org, 'organization')->create([
            'name' => 'Competent', 'repeating' => true, 'std_freq_id' => $freq->id, 'as_needed' => false,
        ]);
        $authorized = $this->training($org, 'Authorized');
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $competent->id,
            'completion_date' => now()->subDays(30)->toDateString(),
            'expire_date' => now()->addDays(700)->toDateString(),
        ]);
        $this->assertSame('not_started', $ta->fresh()->status);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => $competent->id,
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
    }

    public function test_clearing_the_pointer_resyncs_back(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $authorized->update(['superseded_by_id' => $competent->id]);
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $competent->id,
            'completion_date' => now()->subDays(30)->toDateString(),
            'expire_date' => now()->addDays(700)->toDateString(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);
        $this->assertSame('current', $ta->fresh()->status);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'superseded_by_id' => null,
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('not_started', $ta->status);
        $this->assertNull($ta->satisfied_via_training_id);
    }

    public function test_repointing_resyncs_descendants_too(): void
    {
        // Repointing Competent re-routes everything BELOW it: Authorized
        // chains through Competent, so its coverage changes as well.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $qualified = $this->training($org, 'Qualified');
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $authorized->update(['superseded_by_id' => $competent->id]);
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $qualified->id,
            'completion_date' => now()->subDays(10)->toDateString(),
            'expire_date' => now()->addDays(900)->toDateString(),
        ]);
        $this->assertSame('not_started', $ta->fresh()->status);

        // Wiring Competent → Qualified links Authorized to Qualified transitively.
        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$competent->id}", $this->payload($competent, [
                'superseded_by_id' => $qualified->id,
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($qualified->id, $ta->satisfied_via_training_id);
    }

    /** Wire a covered assignment: Authorized satisfied via a Competent credit. */
    private function coveredAssignment(Organization $org, User $user): array
    {
        $competent = $this->training($org, 'Competent Person');
        $authorized = $this->training($org, 'Authorized Person');
        $authorized->update(['superseded_by_id' => $competent->id]);
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $competent->id,
            'completion_date' => now()->subDays(30)->toDateString(),
            'expire_date' => now()->addDays(700)->toDateString(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);

        return [$ta, $competent];
    }

    public function test_assignment_rows_carry_the_via_training(): void
    {
        // The pill's data source: without the name on the wire, the UI can
        // only show an unexplained green — the audit answer stays buried in
        // the database.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        [, $competent] = $this->coveredAssignment($org, $user);

        $response = $this->actingAs($admin)
            ->getJson('/api/training-assignments?user_id='.$user->id)
            ->assertOk();

        $row = collect($response->json())->firstOrFail();
        $this->assertSame($competent->id, $row['satisfied_via_training_id']);
        $this->assertSame('Competent Person', $row['satisfied_via_training_name']);
    }

    public function test_user_compliance_rows_carry_the_via_training(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        [, $competent] = $this->coveredAssignment($org, $user);

        $response = $this->actingAs($admin)
            ->getJson("/api/users/{$user->id}/training-compliance")
            ->assertOk();

        $rows = collect($response->json('groups'))->flatten(1);
        $covered = $rows->firstWhere('training_name', 'Authorized Person');
        $this->assertNotNull($covered);
        $this->assertSame('Competent Person', $covered['satisfied_via_training_name']);
    }

    public function test_needs_action_rows_carry_the_via_training(): void
    {
        // A lapsed credential still reads overdue WITH its via, so the
        // needs-action list can say "was covered via Competent, now lapsed".
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $competent = $this->training($org, 'Competent Person');
        $authorized = $this->training($org, 'Authorized Person');
        $authorized->update(['superseded_by_id' => $competent->id]);
        TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $competent->id,
            'completion_date' => now()->subDays(800)->toDateString(),
            'expire_date' => now()->subDays(70)->toDateString(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);

        $response = $this->actingAs($admin)
            ->getJson('/api/dashboard/needs-action')
            ->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('training_name', 'Authorized Person');
        $this->assertNotNull($row);
        $this->assertSame('overdue', $row['status']);
        $this->assertSame('Competent Person', $row['satisfied_via_training_name']);
    }

    public function test_show_page_exposes_the_pointer_for_the_form(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $authorized->update(['superseded_by_id' => $competent->id]);

        $this->actingAs($admin)
            ->get(route('trainings.show', $authorized))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('training.superseded_by_id', $competent->id)
            );
    }
}
