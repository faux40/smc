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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Training hierarchy: a "higher" training satisfies a "lower" one.
 *
 * The relation is a set of upward edges (`training_satisfiers`) — a lower
 * training may name SEVERAL higher ones, ANY of which satisfies it (OR), so
 * Authorized → Competent → Qualified chains transitively and Initial/Refresher
 * pairs sit side by side as alternate branches of a DAG. Resolution
 * happens INSIDE the status recalc, the one seam every consumer already reads
 * through, so the feature adds zero read-time cost: the result lands on the
 * materialized `training_assignments` columns like every other status fact,
 * with `satisfied_via_training_id` recording the covering training (the audit
 * answer).
 *
 * Agreed semantics (2026-08-06, John):
 *  - the credential carries: coverage expires when the covering completion
 *    expires, under ITS training's rules — never re-derived from the lower's
 *  - best effective expiry wins; the training's own completion on ties
 *  - the chain hops over soft-deleted nodes, whose completions still count
 *  - no virtual certs (not this file's concern — nothing here mints one)
 */
class TrainingHierarchyStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function freq(Organization $org, string $name, int $days): StdFrequency
    {
        return StdFrequency::create(['org_id' => $org->id, 'name' => $name, 'repeat_days' => $days]);
    }

    private function training(Organization $org, string $name, ?StdFrequency $freq = null, ?Training $supersededBy = null): Training
    {
        $t = Training::factory()->for($org, 'organization')->create([
            'name' => $name,
            'repeating' => $freq !== null,
            'std_freq_id' => $freq?->id,
            'as_needed' => $freq === null,
        ]);

        if ($supersededBy !== null) {
            $t->satisfiers()->attach($supersededBy->id, ['org_id' => $org->id]);
        }

        return $t;
    }

    private function assign(Organization $org, User $user, Training $training): TrainingAssignment
    {
        $ta = TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
        ]);
        app(RecalculateTrainingStatus::class)->handle($user->id, $training->id);

        return $ta;
    }

    private function complete(
        Organization $org,
        User $user,
        Training $training,
        string $completedOn,
        ?string $expiresOn,
    ): Completion {
        return Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => $completedOn,
            'expire_date' => $expiresOn,
        ]);
    }

    /** A standard two-level ladder: Authorized (annual) → Competent (2-year). */
    private function ladder(Organization $org): array
    {
        $competent = $this->training($org, 'FP Competent Person', $this->freq($org, 'Biennial', 730));
        $authorized = $this->training($org, 'FP Authorized Person', $this->freq($org, 'Annual', 365), $competent);

        return [$authorized, $competent];
    }

    public function test_a_current_higher_completion_satisfies_the_lower_assignment(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->assertSame('not_started', $ta->fresh()->status);

        $this->complete($org, $user, $competent, now()->subDays(30)->toDateString(), now()->addDays(700)->toDateString());

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
    }

    public function test_the_credential_carries_its_own_expiry(): void
    {
        // Competent runs two years; Authorized alone runs one. Coverage lasts
        // as long as the credential does — the covering completion's own
        // expire_date lands on the lower assignment untouched.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $expires = now()->addDays(650)->toDateString();
        $this->complete($org, $user, $competent, now()->subDays(80)->toDateString(), $expires);

        $this->assertSame($expires, $ta->fresh()->expires_at->toDateString());
    }

    public function test_a_covering_completion_without_explicit_expiry_uses_its_own_trainings_cycle(): void
    {
        // No expire_date on the credential → derive from the COVERING
        // training's repeat_days (730), never the lower's (365). Completed 100
        // days ago: under Competent's rules that's 630 days out; under
        // Authorized's it would be 265 — the difference is the test.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $completed = now()->subDays(100);
        $this->complete($org, $user, $competent, $completed->toDateString(), null);

        $this->assertSame(
            $completed->addDays(730)->toDateString(),
            $ta->fresh()->expires_at->toDateString(),
        );
    }

    public function test_coverage_is_transitive_up_the_chain(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $qualified = $this->training($org, 'FP Qualified', $this->freq($org, 'Triennial', 1095));
        $competent = $this->training($org, 'FP Competent', $this->freq($org, 'Biennial', 730), $qualified);
        $authorized = $this->training($org, 'FP Authorized', $this->freq($org, 'Annual', 365), $competent);
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $qualified, now()->subDays(10)->toDateString(), now()->addDays(1000)->toDateString());

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($qualified->id, $ta->satisfied_via_training_id);
    }

    public function test_an_unrelated_trainings_completion_does_not_satisfy(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized] = $this->ladder($org);
        $forklift = $this->training($org, 'Forklift', $this->freq($org, 'Annual2', 365));
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $forklift, now()->subDays(5)->toDateString(), now()->addDays(360)->toDateString());

        $this->assertSame('not_started', $ta->fresh()->status);
    }

    public function test_coverage_never_flows_downhill(): void
    {
        // Authorized does NOT satisfy Competent — the whole point is
        // one-directional.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $competent);

        $this->complete($org, $user, $authorized, now()->subDays(5)->toDateString(), now()->addDays(360)->toDateString());

        $this->assertSame('not_started', $ta->fresh()->status);
    }

    public function test_own_completion_wins_ties_and_keeps_via_null(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $expires = now()->addDays(300)->toDateString();
        $this->complete($org, $user, $competent, now()->subDays(10)->toDateString(), $expires);
        $this->complete($org, $user, $authorized, now()->subDays(10)->toDateString(), $expires);

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertNull($ta->satisfied_via_training_id);
    }

    public function test_an_older_but_longer_lived_credential_outlasts_the_own_completion(): void
    {
        // The case that rules out "latest completion date wins across the
        // pool": Sam completed Authorized recently (expires sooner) and
        // Competent earlier (expires later). The Competent credential must
        // govern, or Sam reads overdue while holding a valid higher credit.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $competent, now()->subDays(400)->toDateString(), now()->addDays(330)->toDateString());
        $this->complete($org, $user, $authorized, now()->subDays(200)->toDateString(), now()->addDays(165)->toDateString());

        $ta->refresh();
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
        $this->assertSame(now()->addDays(330)->toDateString(), $ta->expires_at->toDateString());
    }

    public function test_an_expired_credential_reads_overdue_with_the_via_kept(): void
    {
        // Lapsed coverage stays attributed: "was covered via Competent, now
        // lapsed" is more useful on a report than an unexplained overdue.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $competent, now()->subDays(800)->toDateString(), now()->subDays(70)->toDateString());

        $ta->refresh();
        $this->assertSame('overdue', $ta->status);
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
    }

    public function test_the_chain_hops_over_a_soft_deleted_middle_training(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $qualified = $this->training($org, 'Qualified', $this->freq($org, 'Triennial', 1095));
        $competent = $this->training($org, 'Competent', $this->freq($org, 'Biennial', 730), $qualified);
        $authorized = $this->training($org, 'Authorized', $this->freq($org, 'Annual', 365), $competent);
        $ta = $this->assign($org, $user, $authorized);

        $competent->delete();
        $this->complete($org, $user, $qualified, now()->subDays(10)->toDateString(), now()->addDays(1000)->toDateString());

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($qualified->id, $ta->satisfied_via_training_id);
    }

    public function test_a_soft_deleted_trainings_own_completions_still_count(): void
    {
        // Deleting a training from the library doesn't un-train anyone: an
        // existing Competent credential keeps covering Authorized until it
        // expires on its own.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->complete($org, $user, $competent, now()->subDays(30)->toDateString(), now()->addDays(700)->toDateString());
        $this->assertSame('current', $ta->fresh()->status);

        $competent->delete();
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);

        $this->assertSame('current', $ta->fresh()->status);
    }

    public function test_completing_the_higher_training_fans_down_via_the_observer(): void
    {
        // The lower TA must flip WITHOUT anyone recalcing it explicitly — the
        // CompletionObserver has to walk down the ladder itself, or class
        // close-outs and manual completions on the higher training leave the
        // lower rows stale until the nightly watchdog.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);

        $completion = $this->complete($org, $user, $competent, now()->subDays(1)->toDateString(), now()->addDays(700)->toDateString());
        $this->assertSame('current', $ta->fresh()->status, 'create must fan down');

        $completion->delete();
        $ta->refresh();
        $this->assertSame('not_started', $ta->status, 'delete must fan down too');
        $this->assertNull($ta->satisfied_via_training_id, 'via must clear with the credit');
    }

    public function test_a_cycle_in_the_data_terminates_rather_than_hanging(): void
    {
        // Writes refuse cycles, but the resolver must still be safe against
        // one arriving by console or migration — belt and braces.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        DB::table('training_satisfiers')->insert([
            'org_id' => $org->id,
            'training_id' => $competent->id,
            'satisfied_by_id' => $authorized->id,
        ]);
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $competent, now()->subDays(5)->toDateString(), now()->addDays(700)->toDateString());

        $this->assertSame('current', $ta->fresh()->status);
    }

    public function test_the_batch_recalc_path_resolves_coverage_too(): void
    {
        // handleAll drives the org-wide resync and bulk assignment; it must
        // agree with the single-pair path or a resync would quietly strip
        // coverage from every row it touches.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $competent] = $this->ladder($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->complete($org, $user, $competent, now()->subDays(30)->toDateString(), now()->addDays(700)->toDateString());
        $this->assertSame('current', $ta->fresh()->status);

        // Wipe the materialized columns, then resync the org.
        $ta->update(['status' => 'not_started', 'expires_at' => null, 'last_completed_at' => null, 'satisfied_via_training_id' => null]);
        app(RecalculateTrainingStatus::class)->handleAll($org->id);

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($competent->id, $ta->satisfied_via_training_id);
    }

    // ---- OR-satisfiers: several higher trainings, any one covers ----------

    /** Authorized ← {Initial, Refresher}: John's case, two alternate branches. */
    private function orPair(Organization $org): array
    {
        $initial = $this->training($org, 'FP Competent Initial', $this->freq($org, 'Biennial', 730));
        $refresher = $this->training($org, 'FP Competent Refresher', $this->freq($org, 'Annual', 365));
        // A frequency, not as_needed — an as-needed-only assignment pins its
        // status to 'as_needed', masking the very transitions under test.
        $authorized = $this->training($org, 'FP Authorized Person', $this->freq($org, 'Authorized Annual', 365));
        $authorized->satisfiers()->attach($initial->id, ['org_id' => $org->id]);
        $authorized->satisfiers()->attach($refresher->id, ['org_id' => $org->id]);

        return [$authorized, $initial, $refresher];
    }

    public function test_either_satisfier_covers_the_lower_assignment(): void
    {
        // One credit on the FIRST branch…
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $initial] = $this->orPair($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->complete($org, $user, $initial, now()->subDays(30)->toDateString(), now()->addDays(700)->toDateString());

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($initial->id, $ta->satisfied_via_training_id);

        // …and, independently, one on the SECOND.
        $org2 = Organization::factory()->create();
        [$authorized2, , $refresher2] = $this->orPair($org2);
        $user2 = User::factory()->for($org2, 'organization')->create();
        $ta2 = $this->assign($org2, $user2, $authorized2);
        $this->complete($org2, $user2, $refresher2, now()->subDays(10)->toDateString(), now()->addDays(300)->toDateString());

        $ta2->refresh();
        $this->assertSame('current', $ta2->status);
        $this->assertSame($refresher2->id, $ta2->satisfied_via_training_id);
    }

    public function test_best_expiry_wins_across_branches(): void
    {
        // Credits on BOTH branches: the one whose credential lives longer
        // carries the assignment — same best-effective-expiry rule that
        // already governed chains, now across siblings.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $initial, $refresher] = $this->orPair($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->complete($org, $user, $refresher, now()->subDays(5)->toDateString(), now()->addDays(200)->toDateString());
        $this->complete($org, $user, $initial, now()->subDays(60)->toDateString(), now()->addDays(650)->toDateString());

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($initial->id, $ta->satisfied_via_training_id);
        $this->assertSame(now()->addDays(650)->toDateString(), $ta->expires_at?->toDateString());
    }

    public function test_completing_either_branch_fans_down_to_the_shared_child(): void
    {
        // The observer fan-down must follow EVERY downward branch: a credit
        // landing on Refresher reaches Authorized even though Authorized is
        // also wired under Initial.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, , $refresher] = $this->orPair($org);
        $ta = $this->assign($org, $user, $authorized);
        $this->assertSame('not_started', $ta->fresh()->status);

        // Through the model event, exactly as the completions API fires it.
        Completion::factory()->for($org, 'organization')->for($user, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $refresher->id,
            'completion_date' => now()->subDays(3)->toDateString(),
            'expire_date' => now()->addDays(360)->toDateString(),
        ]);

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($refresher->id, $ta->satisfied_via_training_id);
    }

    public function test_a_diamond_resolves_once_and_correctly(): void
    {
        // Authorized ← {Initial, Refresher}, both ← Trainer: the top credit
        // reaches the bottom along two converging paths and must cover it
        // exactly once, via the trainer credential.
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        [$authorized, $initial, $refresher] = $this->orPair($org);
        $trainer = $this->training($org, 'FP Trainer', $this->freq($org, 'Triennial', 1095));
        $initial->satisfiers()->attach($trainer->id, ['org_id' => $org->id]);
        $refresher->satisfiers()->attach($trainer->id, ['org_id' => $org->id]);
        $ta = $this->assign($org, $user, $authorized);

        $this->complete($org, $user, $trainer, now()->subDays(30)->toDateString(), now()->addDays(1000)->toDateString());
        app(RecalculateTrainingStatus::class)->handle($user->id, $authorized->id);

        $ta->refresh();
        $this->assertSame('current', $ta->status);
        $this->assertSame($trainer->id, $ta->satisfied_via_training_id);
        $this->assertSame(now()->addDays(1000)->toDateString(), $ta->expires_at?->toDateString());
    }
}
