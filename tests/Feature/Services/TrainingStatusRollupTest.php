<?php

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\TrainingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The org-level rollups (K3/K4 dashboard + J4 digest) now aggregate the
 * materialized `training_assignments.status` in SQL instead of hydrating every
 * assignment into PHP. These pin the counts, worst-first ordering, and the
 * bounded query cost the rewrite must preserve.
 */
class TrainingStatusRollupTest extends TestCase
{
    use RefreshDatabase;

    private function ta(Organization $org, User $user, string $status, array $attrs = []): TrainingAssignment
    {
        $training = Training::factory()->for($org, 'organization')->create();

        return TrainingAssignment::factory()->create(array_merge([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'status' => $status,
        ], $attrs));
    }

    public function test_org_summary_counts_each_bucket_and_distinct_overdue_users(): void
    {
        $org = Organization::factory()->create();
        $alice = User::factory()->for($org, 'organization')->create();
        $bob = User::factory()->for($org, 'organization')->create();

        // Alice: 2 overdue + 1 current. Bob: 1 overdue + 1 due_soon.
        $this->ta($org, $alice, 'overdue');
        $this->ta($org, $alice, 'overdue');
        $this->ta($org, $alice, 'current');
        $this->ta($org, $bob, 'overdue');
        $this->ta($org, $bob, 'due_soon');

        $summary = app(TrainingStatusService::class)->orgSummary($org);

        $this->assertSame(3, $summary['counts']['overdue']);
        $this->assertSame(1, $summary['counts']['due_soon']);
        $this->assertSame(1, $summary['counts']['current']);
        $this->assertSame(0, $summary['counts']['not_started']);
        $this->assertSame(0, $summary['counts']['as_needed']);
        $this->assertSame(5, $summary['total_assignments']);
        $this->assertSame(2, $summary['total_users']);
        // Distinct users with an overdue TA, not the overdue TA count.
        $this->assertSame(2, $summary['users_with_overdue']);
    }

    public function test_top_overdue_users_are_ranked_worst_first(): void
    {
        $org = Organization::factory()->create();
        $worst = User::factory()->for($org, 'organization')->create(['f_name' => 'Wanda', 'l_name' => 'Worst']);
        $mild = User::factory()->for($org, 'organization')->create(['f_name' => 'Milo', 'l_name' => 'Mild']);

        $this->ta($org, $worst, 'overdue');
        $this->ta($org, $worst, 'overdue');
        $this->ta($org, $worst, 'overdue');
        $this->ta($org, $mild, 'overdue');

        $rows = app(TrainingStatusService::class)->topOverdueUsers($org, 10);

        $this->assertSame($worst->id, $rows[0]['user_id']);
        $this->assertSame(3, $rows[0]['overdue_count']);
        $this->assertSame($mild->id, $rows[1]['user_id']);
        $this->assertSame(1, $rows[1]['overdue_count']);
        $this->assertSame('Worst, Wanda', $rows[0]['name']);
    }

    public function test_top_due_soon_is_ordered_by_soonest_expiry(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();

        $later = $this->ta($org, $user, 'due_soon', ['expires_at' => now()->addDays(20)]);
        $sooner = $this->ta($org, $user, 'due_soon', ['expires_at' => now()->addDays(3)]);

        $rows = app(TrainingStatusService::class)->topDueSoon($org, 10);

        $this->assertSame([$sooner->id, $later->id], [
            TrainingAssignment::where('name', $rows[0]['training_name'])->value('id'),
            TrainingAssignment::where('name', $rows[1]['training_name'])->value('id'),
        ]);
        $this->assertSame(3, $rows[0]['days_until_due']);
        $this->assertNotNull($rows[0]['user_name']);
    }

    public function test_users_compliance_summary_sorts_and_paginates_in_sql(): void
    {
        $org = Organization::factory()->create();
        $worst = User::factory()->for($org, 'organization')->create();
        $middle = User::factory()->for($org, 'organization')->create();
        $none = User::factory()->for($org, 'organization')->create();

        $this->ta($org, $worst, 'overdue');
        $this->ta($org, $worst, 'overdue');
        $this->ta($org, $middle, 'overdue');

        $result = app(TrainingStatusService::class)->usersComplianceSummary($org, [
            'sort' => 'overdue',
            'per_page' => 2,
            'page' => 1,
        ]);

        $this->assertSame(3, $result['meta']['total']);
        $this->assertSame(2, $result['meta']['last_page']);
        $this->assertCount(2, $result['data']);
        // Overdue-descending: worst (2) then middle (1) on page 1.
        $this->assertSame($worst->id, $result['data'][0]['user_id']);
        $this->assertSame(2, $result['data'][0]['counts']['overdue']);
        $this->assertSame($middle->id, $result['data'][1]['user_id']);
        $this->assertSame('overdue', $result['data'][1]['overall_status']);
    }

    public function test_rollups_use_a_bounded_number_of_queries(): void
    {
        $org = Organization::factory()->create();

        // Many users, many assignments — the aggregate cost must not scale.
        foreach (User::factory()->for($org, 'organization')->count(15)->create() as $u) {
            $this->ta($org, $u, 'overdue');
            $this->ta($org, $u, 'due_soon');
        }

        $service = app(TrainingStatusService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->orgSummary($org);
        // One GROUP-less aggregate + the total_users count.
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));

        DB::flushQueryLog();
        $service->usersComplianceSummary($org, ['per_page' => 25]);
        // Paginate = count + page; + one tags eager-load. Constant in row count.
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
