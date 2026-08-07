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
 * Write side of the training hierarchy: setting `satisfied_by_ids` through
 * the trainings API. A training may name SEVERAL higher trainings — any one
 * of their credentials satisfies it (OR), so the graph is a DAG: diamonds are
 * legal, cycles are not. The engine trusts the data, so the API must refuse
 * what the engine merely survives — cross-org edges, self-reference, cycles.
 * Re-wiring resyncs the affected assignments immediately: an admin who wires
 * Authorized under Competent expects the compliance page to move now, not at
 * the nightly watchdog.
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

    private function wire(Training $child, Training ...$parents): void
    {
        foreach ($parents as $parent) {
            $child->satisfiers()->attach($parent->id, ['org_id' => $child->org_id]);
        }
    }

    /** @return list<string> */
    private function satisfierIds(Training $t): array
    {
        return $t->satisfiers()->pluck('trainings.id')->all();
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

    public function test_update_sets_and_returns_the_satisfier_set(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $initial = $this->training($org, 'Competent Initial');
        $refresher = $this->training($org, 'Competent Refresher');

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [$initial->id, $refresher->id],
            ]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$initial->id, $refresher->id],
            $this->satisfierIds($authorized),
        );
    }

    public function test_update_clears_the_set_with_an_empty_array(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $this->wire($authorized, $competent);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [],
            ]))
            ->assertOk();

        $this->assertSame([], $this->satisfierIds($authorized));
    }

    public function test_store_accepts_satisfiers(): void
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
                'satisfied_by_ids' => [$competent->id],
            ])
            ->assertCreated();

        $this->assertSame(
            [$competent->id],
            $this->satisfierIds(Training::findOrFail($response->json('id'))),
        );
    }

    public function test_a_cross_org_satisfier_is_refused(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $foreign = $this->training($otherOrg, 'Foreign Competent');

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [$foreign->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('satisfied_by_ids.0');
    }

    public function test_a_training_satisfying_itself_is_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $other = $this->training($org, 'Other');

        // Buried in an otherwise-valid set — the whole set is refused.
        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [$other->id, $authorized->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('satisfied_by_ids');
    }

    public function test_a_two_node_cycle_is_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $a = $this->training($org, 'A');
        $b = $this->training($org, 'B');
        $this->wire($a, $b);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$b->id}", $this->payload($b, [
                'satisfied_by_ids' => [$a->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('satisfied_by_ids');
    }

    public function test_a_deep_cycle_through_one_branch_is_refused(): void
    {
        // C's proposed set is [D, A] — D is innocent, but A chains down to C
        // through B. One bad branch poisons the whole set.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $a = $this->training($org, 'A');
        $b = $this->training($org, 'B');
        $c = $this->training($org, 'C');
        $d = $this->training($org, 'D');
        $this->wire($a, $b);
        $this->wire($b, $c);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$c->id}", $this->payload($c, [
                'satisfied_by_ids' => [$d->id, $a->id],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('satisfied_by_ids');
    }

    public function test_a_diamond_is_legal(): void
    {
        // Authorized ← {Initial, Refresher}, both ← Trainer. Two paths to the
        // same ancestor is convergence, not a loop — the guard must tell the
        // difference.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $authorized = $this->training($org, 'Authorized');
        $initial = $this->training($org, 'Competent Initial');
        $refresher = $this->training($org, 'Competent Refresher');
        $trainer = $this->training($org, 'Trainer');
        $this->wire($initial, $trainer);
        $this->wire($refresher, $trainer);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [$initial->id, $refresher->id],
            ]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$initial->id, $refresher->id],
            $this->satisfierIds($authorized),
        );
    }

    public function test_wiring_resyncs_affected_assignments_immediately(): void
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
                'satisfied_by_ids' => [$competent->id],
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
    }

    public function test_adding_a_second_satisfier_covers_through_it(): void
    {
        // John's case verbatim: the credit sits on Refresher, and Authorized
        // is currently wired only under Initial. Widening the set to
        // {Initial, Refresher} must light Authorized up via Refresher.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $initial = $this->training($org, 'Competent Initial');
        $refresher = $this->training($org, 'Competent Refresher');
        $authorized = $this->training($org, 'Authorized');
        $this->wire($authorized, $initial);
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $authorized->id,
            'name' => $authorized->name,
        ]);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $refresher->id,
            'completion_date' => now()->subDays(30)->toDateString(),
            'expire_date' => now()->addDays(700)->toDateString(),
        ]);
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);
        $this->assertSame('not_started', $ta->fresh()->status);

        $this->actingAs($admin)
            ->patchJson("/api/trainings/{$authorized->id}", $this->payload($authorized, [
                'satisfied_by_ids' => [$initial->id, $refresher->id],
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($refresher->id, $ta->satisfied_via_training_id);
    }

    public function test_clearing_the_set_resyncs_back(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $this->wire($authorized, $competent);
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
                'satisfied_by_ids' => [],
            ]))
            ->assertOk();

        $ta->refresh();
        $this->assertSame('not_started', $ta->status);
        $this->assertNull($ta->satisfied_via_training_id);
    }

    public function test_rewiring_resyncs_descendants_too(): void
    {
        // Rewiring Competent re-routes everything BELOW it: Authorized
        // chains through Competent, so its coverage changes as well.
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $user = User::factory()->for($org, 'organization')->create();
        $qualified = $this->training($org, 'Qualified');
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $this->wire($authorized, $competent);
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
                'satisfied_by_ids' => [$qualified->id],
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
        $this->wire($authorized, $competent);
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
        $this->wire($authorized, $competent);
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

    public function test_show_page_exposes_the_satisfier_set_for_the_form(): void
    {
        $org = Organization::factory()->create();
        $admin = $this->admin($org);
        $competent = $this->training($org, 'Competent');
        $authorized = $this->training($org, 'Authorized');
        $this->wire($authorized, $competent);

        $this->actingAs($admin)
            ->get(route('trainings.show', $authorized))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('training.satisfied_by_ids', [$competent->id])
            );
    }
}
