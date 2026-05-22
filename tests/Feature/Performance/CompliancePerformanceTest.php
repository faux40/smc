<?php

namespace Tests\Feature\Performance;

use App\Models\Assignment;
use App\Models\Organization;
use App\Models\User;
use App\Services\UserComplianceCalculator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 16.6 — performance guards for the compliance calculator.
 *
 * compute() is per-user N+1-free by design (eager loads + pre-indexing); the
 * org rollups (summarizeOrg / topOverdueUsers / topDueSoon) loop every user
 * calling compute(). That per-user pass is O(users) on purpose — a set-based
 * rewrite is deferred until a real org approaches the ceiling. These tests
 * (a) pin compute() as volume-independent, (b) ensure org-level data isn't
 * re-fetched per user, and (c) document the linear ceiling as a budget.
 */
class CompliancePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_compute_query_count_is_independent_of_assignment_volume(): void
    {
        $org = Organization::factory()->create();
        $light = User::factory()->for($org, 'organization')->create();
        $heavy = User::factory()->for($org, 'organization')->create();

        Assignment::factory()->count(2)->create(['org_id' => $org->id, 'user_id' => $light->id]);
        Assignment::factory()->count(12)->create(['org_id' => $org->id, 'user_id' => $heavy->id]);

        $calc = app(UserComplianceCalculator::class);

        $lightQueries = $this->countQueries(fn () => $calc->compute($light));
        $heavyQueries = $this->countQueries(fn () => $calc->compute($heavy));

        // Eager loading must keep the query count flat as row volume grows —
        // 6× the assignments must not mean more queries.
        $this->assertSame(
            $lightQueries,
            $heavyQueries,
            "compute() N+1: {$lightQueries} queries for 2 assignments vs {$heavyQueries} for 12.",
        );
    }

    public function test_summarize_org_loads_std_frequencies_once_not_per_user(): void
    {
        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->count(5)->create();

        $calc = app(UserComplianceCalculator::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $calc->summarizeOrg($org);

        $freqQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'std_frequencies'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            $freqQueries,
            "Org-level std_frequencies was queried {$freqQueries}× — should be loaded once per pass, not per user.",
        );
    }

    public function test_users_compliance_summary_loads_freqs_and_tags_once_not_per_user(): void
    {
        $org = Organization::factory()->create();
        User::factory()->for($org, 'organization')->count(6)->create();

        $calc = app(UserComplianceCalculator::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $calc->usersComplianceSummary($org);
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $freq = $log->filter(fn ($q) => str_contains($q['query'], 'std_frequencies'))->count();
        $tags = $log->filter(fn ($q) => str_contains($q['query'], 'taggables'))->count();

        $this->assertLessThanOrEqual(1, $freq, "std_frequencies queried {$freq}× — should be once.");
        $this->assertLessThanOrEqual(1, $tags, "tags eager-load queried {$tags}× — should be once for all users.");
    }

    public function test_summarize_org_stays_within_a_linear_query_budget(): void
    {
        // Soak guard documenting the O(users) ceiling. ~5 per-user queries +
        // a small fixed overhead; budget generously and assert it holds so a
        // future regression (or an accidental per-user org query) trips it.
        $org = Organization::factory()->create();
        $userCount = 30;
        $users = User::factory()->for($org, 'organization')->count($userCount)->create();
        foreach ($users->take(10) as $u) {
            Assignment::factory()->create(['org_id' => $org->id, 'user_id' => $u->id]);
        }

        $calc = app(UserComplianceCalculator::class);
        $queries = $this->countQueries(fn () => $calc->summarizeOrg($org));

        $budget = 6 * $userCount + 10;
        $this->assertLessThanOrEqual(
            $budget,
            $queries,
            "summarizeOrg used {$queries} queries for {$userCount} users (budget {$budget}).",
        );
    }
}
