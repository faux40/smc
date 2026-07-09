<?php

namespace Tests\Feature\Tenancy;

use App\Models\AssignmentSource;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Tag;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * F12 — the exportable "current compliance status" snapshot (the audit
 * document): every (user, assigned training) with its current status / due
 * date / source, including never-started people. On-screen JSON + PDF + CSV.
 */
class ComplianceStatusReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Pdf::fake();
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    private function ta(Organization $org, Training $training, User $user, array $attrs): TrainingAssignment
    {
        return TrainingAssignment::factory()->create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            ...$attrs,
        ]);
    }

    public function test_snapshot_includes_never_started_users(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift']);
        $newbie = User::factory()->for($org, 'organization')->create(['f_name' => 'Nadia', 'l_name' => 'New']);
        // A never-started assignment: no completion → not_started.
        $this->ta($org, $training, $newbie, [
            'status' => 'not_started',
            'last_completed_at' => null,
            'expires_at' => null,
        ]);

        $rows = $this->actingAs($manager)
            ->getJson(route('reports.compliance-status'))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('New, Nadia', $rows[0]['user']);
        $this->assertSame('Not started', $rows[0]['status']);
        $this->assertSame('not_started', $rows[0]['status_key']);
        $this->assertSame('—', $rows[0]['expires_at']);
        $this->assertSame('—', $rows[0]['days_until_due']);
    }

    public function test_status_expiry_days_and_source_are_correct(): void
    {
        Carbon::setTestNow('2026-06-15');
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Site Safety']);

        $overdueUser = User::factory()->for($org, 'organization')->create(['l_name' => 'Odue']);
        $overdue = $this->ta($org, $training, $overdueUser, [
            'status' => 'overdue',
            'last_completed_at' => '2025-06-10',
            'expires_at' => '2026-06-10', // 5 days ago
        ]);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $overdue->id]);

        // A direct (no requirement) current assignment.
        $currentUser = User::factory()->for($org, 'organization')->create(['l_name' => 'Curr']);
        $this->ta($org, $training, $currentUser, [
            'status' => 'current',
            'last_completed_at' => '2026-06-01',
            'expires_at' => '2026-12-01',
        ]);

        $rows = collect($this->actingAs($manager)
            ->getJson(route('reports.compliance-status'))
            ->assertOk()
            ->json('data'));

        $odue = $rows->firstWhere('status', 'Overdue');
        $this->assertSame('2026-06-10', $odue['expires_at']);
        $this->assertSame('-5', $odue['days_until_due']); // negative = overdue
        $this->assertSame('Site Safety', $odue['source']);

        $curr = $rows->firstWhere('status', 'Current');
        $this->assertSame('Direct', $curr['source']);
        $this->assertSame('169', $curr['days_until_due']); // 2026-06-15 → 2026-12-01
    }

    public function test_filters_by_status_tag_requirement_and_search(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Site Safety']);
        $tag = Tag::factory()->for($org, 'organization')->create();

        $overdueUser = User::factory()->for($org, 'organization')->create(['f_name' => 'Olivia', 'l_name' => 'Odue']);
        $overdueUser->tags()->attach($tag->id);
        $overdue = $this->ta($org, $training, $overdueUser, ['status' => 'overdue', 'expires_at' => '2020-01-01']);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $overdue->id]);

        $currentUser = User::factory()->for($org, 'organization')->create(['f_name' => 'Carl', 'l_name' => 'Curr']);
        $this->ta($org, $training, $currentUser, ['status' => 'current']);

        $statuses = fn (array $q) => collect($this->actingAs($manager)
            ->getJson(route('reports.compliance-status', $q))->assertOk()->json('data'))
            ->pluck('status')->all();

        // Status multi-select.
        $this->assertSame(['Overdue'], $statuses(['statuses' => ['overdue']]));
        $this->assertEqualsCanonicalizing(['Overdue', 'Current'], $statuses(['statuses' => ['overdue', 'current']]));

        // Tag filter → only the tagged user's assignment.
        $tagged = collect($this->actingAs($manager)
            ->getJson(route('reports.compliance-status', ['tags' => [$tag->id]]))->assertOk()->json('data'));
        $this->assertSame(['Odue, Olivia'], $tagged->pluck('user')->all());

        // requirement_id scope → only the assignment that requirement sources.
        $scoped = collect($this->actingAs($manager)
            ->getJson(route('reports.compliance-status', ['requirement_id' => $req->id]))->json('data'));
        $this->assertSame(['Overdue'], $scoped->pluck('status')->all());

        // Search by name.
        $found = $statuses(['q' => 'curr']);
        $this->assertSame(['Current'], $found);
    }

    public function test_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        $trainingB = Training::factory()->for($orgB, 'organization')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();
        $this->ta($orgB, $trainingB, $userB, ['status' => 'overdue']);

        $rows = $this->actingAs($managerA)
            ->getJson(route('reports.compliance-status'))->assertOk()->json('data');

        $this->assertCount(0, $rows);
    }

    public function test_forbidden_for_non_manager(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)->getJson(route('reports.compliance-status'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.compliance-status-export'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.compliance-status-export', ['format' => 'csv']))->assertForbidden();
    }

    public function test_export_pdf_carries_rows_columns_and_scope_subtitle(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $req = Requirement::factory()->for($org, 'organization')->create(['name' => 'Site Safety']);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee', 'employee_number' => 'EMP-9']);
        $ta = $this->ta($org, $training, $user, ['status' => 'overdue', 'expires_at' => '2020-01-01']);
        AssignmentSource::factory()->forRequirement($req)->create(['training_assignment_id' => $ta->id]);

        $this->actingAs($manager)
            ->get(route('reports.compliance-status-export', ['requirement_id' => $req->id]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['title'] === 'Compliance status'
                && $pdf->viewData['subtitle'] === 'Site Safety'
                && (new Collection($pdf->viewData['columns']))->contains(fn (array $c) => $c['key'] === 'days_until_due')
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam'
                        && $r['training'] === 'CPR'
                        && $r['status'] === 'Overdue'
                        && $r['employee_number'] === 'EMP-9'
                        && $r['source'] === 'Site Safety',
                ),
        );
    }

    public function test_export_pdf_honors_selected_columns_and_grouping(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $yard = User::factory()->for($org, 'organization')->create(['l_name' => 'Lee', 'location' => 'Yard']);
        $dock = User::factory()->for($org, 'organization')->create(['l_name' => 'Roe', 'location' => 'Dock']);
        $this->ta($org, $training, $yard, ['status' => 'overdue']);
        $this->ta($org, $training, $dock, ['status' => 'current']);

        $this->actingAs($manager)
            ->get(route('reports.compliance-status-export', [
                'columns' => ['status', 'user', 'bogus'],
                'group_by' => ['location'],
            ]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $cols = (new Collection($pdf->viewData['columns']))->pluck('key')->all();
            $groups = (new Collection($pdf->viewData['groups']))
                ->where('type', 'group')
                ->map(fn (array $g) => [$g['label'], $g['count']])
                ->values()->all();

            return $cols === ['status', 'user']
                && $groups === [['Location: Dock', 1], ['Location: Yard', 1]];
        });
    }

    public function test_export_csv_streams_header_and_rows(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee', 'employee_number' => 'EMP-1']);
        $this->ta($org, $training, $user, ['status' => 'overdue', 'expires_at' => '2020-01-01']);

        $response = $this->actingAs($manager)
            ->get(route('reports.compliance-status-export', ['format' => 'csv']))
            ->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            'attachment; filename=compliance-status-'.Carbon::now(config('app.display_timezone'))->format('Y-m-d').'.csv',
            $response->headers->get('Content-Disposition'),
        );

        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
        $this->assertSame(
            ['User', 'Employee #', 'Department', 'Location', 'Training', 'Status', 'Expires / Due', 'Days until due', 'Source'],
            $rows[0],
        );
        $this->assertTrue(collect($rows)->contains(
            fn (array $r) => ($r[0] ?? null) === 'Lee, Sam' && ($r[4] ?? null) === 'CPR' && ($r[5] ?? null) === 'Overdue' && ($r[8] ?? null) === 'Direct',
        ));
    }

    public function test_export_csv_honors_filters_columns_and_grouping(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $yard = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee', 'location' => 'Yard']);
        $dock = User::factory()->for($org, 'organization')->create(['f_name' => 'Max', 'l_name' => 'Roe', 'location' => 'Dock']);
        $this->ta($org, $training, $yard, ['status' => 'overdue']);
        $this->ta($org, $training, $dock, ['status' => 'overdue']);

        $response = $this->actingAs($manager)
            ->get(route('reports.compliance-status-export', [
                'format' => 'csv',
                'group_by' => ['location'],
                'columns' => ['user'],
            ]))
            ->assertOk();

        $rows = array_map('str_getcsv', explode("\n", trim($response->streamedContent())));

        $this->assertSame(['User'], $rows[0]);
        // Group label rows, single-cell, sorted alphabetically (Dock before Yard).
        $this->assertSame(['Location: Dock (1)'], $rows[1]);
        $this->assertSame(['Roe, Max'], $rows[2]);
        $this->assertSame(['Location: Yard (1)'], $rows[3]);
        $this->assertSame(['Lee, Sam'], $rows[4]);
    }
}
