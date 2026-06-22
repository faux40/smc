<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Services\ComplianceQuery;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ComplianceQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ta(Organization $org, Training $training, array $attrs): TrainingAssignment
    {
        $user = User::factory()->for($org, 'organization')->create();

        return TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            ...$attrs,
        ]);
    }

    /** One TA in each materialized status bucket for a single training. */
    private function seedOneOfEachBucket(Organization $org, Training $training): void
    {
        foreach (['overdue', 'due_soon', 'current', 'not_started', 'as_needed'] as $status) {
            $this->ta($org, $training, ['status' => $status]);
        }
    }

    public function test_by_training_buckets_match_the_status_rules(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Fall Protection']);
        $this->seedOneOfEachBucket($org, $training);

        $result = (new ComplianceQuery)->byTraining($org);

        $this->assertCount(1, $result['data']);
        $row = $result['data'][0];
        $this->assertSame('Fall Protection', $row['name']);
        $this->assertSame(5, $row['total']);
        $this->assertSame([
            'overdue' => 1,
            'due_soon' => 1,
            'not_started' => 1,
            'current' => 1,
            'as_needed' => 1,
        ], $row['counts']);
    }

    public function test_by_training_is_org_scoped_and_excludes_other_orgs(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $tA = Training::factory()->for($orgA, 'organization')->create();
        $tB = Training::factory()->for($orgB, 'organization')->create();
        $this->ta($orgA, $tA, ['status' => 'overdue']);
        $this->ta($orgB, $tB, ['status' => 'overdue']);

        $result = (new ComplianceQuery)->byTraining($orgA);
        $this->assertCount(1, $result['data']);
        $this->assertSame($tA->id, $result['data'][0]['id']);
    }

    public function test_by_training_searches_and_paginates(): void
    {
        $org = Organization::factory()->create();
        foreach (['Forklift', 'Ladders', 'Confined Space'] as $name) {
            $t = Training::factory()->for($org, 'organization')->create(['name' => $name]);
            $this->ta($org, $t, ['status' => 'overdue']);
        }

        $search = (new ComplianceQuery)->byTraining($org, ['q' => 'fork']);
        $this->assertCount(1, $search['data']);
        $this->assertSame('Forklift', $search['data'][0]['name']);

        $paged = (new ComplianceQuery)->byTraining($org, ['per_page' => 2, 'page' => 1]);
        $this->assertCount(2, $paged['data']);
        $this->assertSame(3, $paged['meta']['total']);
        $this->assertSame(2, $paged['meta']['last_page']);
    }

    public function test_by_training_sorts_by_name(): void
    {
        $org = Organization::factory()->create();
        foreach (['Carter', 'Adams', 'Baker'] as $name) {
            $t = Training::factory()->for($org, 'organization')->create(['name' => $name]);
            $this->ta($org, $t, ['status' => 'not_started']);
        }

        $rows = (new ComplianceQuery)->byTraining($org, ['sort' => 'name', 'dir' => 'asc'])['data'];
        $this->assertSame(['Adams', 'Baker', 'Carter'], array_column($rows, 'name'));
    }

    public function test_by_requirement_counts_actively_sourced_assignments(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'OSHA General']);

        // Two TAs sourced from the requirement (one overdue, one current);
        // one direct-only TA that must NOT count toward the requirement.
        $overdue = $this->ta($org, $training, ['status' => 'overdue']);
        $current = $this->ta($org, $training, ['status' => 'current']);
        $direct = $this->ta($org, $training, ['status' => 'not_started']);

        foreach ([$overdue, $current] as $ta) {
            AssignmentSource::create([
                'training_assignment_id' => $ta->id,
                'sourceable_type' => Requirement::class,
                'sourceable_id' => $req->id,
                'added_at' => now(),
            ]);
        }
        // A removed source must be ignored.
        AssignmentSource::create([
            'training_assignment_id' => $direct->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now()->subMonth(),
            'removed_at' => now(),
        ]);

        $result = (new ComplianceQuery)->byRequirement($org);

        $this->assertCount(1, $result['data']);
        $row = $result['data'][0];
        $this->assertSame('OSHA General', $row['name']);
        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['counts']['overdue']);
        $this->assertSame(1, $row['counts']['current']);
    }

    public function test_not_required_unions_direct_only_and_orphan_completions(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);

        // (a) Direct-only assignment (no requirement source) → counts, overdue.
        $this->ta($org, $training, ['status' => 'overdue']);

        // A requirement-sourced assignment → must be EXCLUDED from not-required.
        $req = Requirement::factory()->for($org, 'organization')->create();
        $sourced = $this->ta($org, $training, ['status' => 'current']);
        AssignmentSource::create([
            'training_assignment_id' => $sourced->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        // (b) Orphan completion: a user completed the training but has no TA for
        //     it → counts, current (no expiry).
        $orphanUser = User::factory()->for($org, 'organization')->create();
        Completion::factory()->for($org, 'organization')->for($orphanUser, 'user')->create([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-01',
            'expire_date' => null,
        ]);

        $res = (new ComplianceQuery)->notRequired($org);

        $row = collect($res['data'])->firstWhere('name', 'CPR');
        $this->assertNotNull($row);
        $this->assertSame(2, $row['total']); // direct-only + orphan; sourced excluded
        $this->assertSame(1, $row['counts']['overdue']);
        $this->assertSame(1, $row['counts']['current']);
    }

    public function test_users_for_training_drilldown_lists_worst_status_first(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $this->ta($org, $training, ['status' => 'current']);
        $this->ta($org, $training, ['status' => 'overdue']);

        $res = (new ComplianceQuery)->usersForTraining($org, $training->id);

        $this->assertSame(2, $res['meta']['total']);
        $this->assertSame('overdue', $res['data'][0]['status']); // worst first
        $this->assertNotNull($res['data'][0]['name']);
    }

    public function test_users_for_requirement_drilldown_only_counts_sourced_assignments(): void
    {
        $org = Organization::factory()->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $req = Requirement::factory()->for($org, 'organization')->create();

        $sourced = $this->ta($org, $training, ['status' => 'overdue']);
        $this->ta($org, $training, ['status' => 'current']); // direct, not sourced
        AssignmentSource::create([
            'training_assignment_id' => $sourced->id,
            'sourceable_type' => Requirement::class,
            'sourceable_id' => $req->id,
            'added_at' => now(),
        ]);

        $res = (new ComplianceQuery)->usersForRequirement($org, $req->id);

        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame($sourced->user_id, $res['data'][0]['user_id']);
    }
}
