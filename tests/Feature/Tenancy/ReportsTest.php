<?php

namespace Tests\Feature\Tenancy;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * Exportable PDF reports (T1). PDF rendering goes through Browsershot, so we
 * fake the renderer and assert the right view + data.
 */
class ReportsTest extends TestCase
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

    public function test_manager_can_export_a_training_record(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $student = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Sam', 'l_name' => 'Lee',
            'employee_number' => 'EMP-7', 'department' => 'Ops', 'location' => 'Yard',
        ]);
        Completion::factory()->for($org, 'organization')->for($student, 'user')->state([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2099-01-10', // far future → Current
            'cert_id' => 'CERT-9',
        ])->create();

        $this->actingAs($manager)
            ->get(route('reports.training-record', $training))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['subtitle'] === 'CPR'
                // Identifying columns + a Status column are present.
                && collect($pdf->viewData['columns'])->pluck('key')->contains('employee_number')
                && collect($pdf->viewData['columns'])->pluck('key')->contains('status')
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam'
                        && $r['cert_id'] === 'CERT-9'
                        && $r['employee_number'] === 'EMP-7'
                        && $r['department'] === 'Ops'
                        && $r['location'] === 'Yard'
                        && $r['status'] === 'Current'
                        && $r['_band'] === 'current',
                ),
        );
    }

    public function test_report_rows_carry_expiry_status_and_colour_band(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift']);

        $cases = [
            ['expire' => '2020-01-01', 'band' => 'expired', 'label' => 'Expired'],
            ['expire' => now()->addDays(5)->toDateString(), 'band' => 'due_soon', 'label' => 'Expires soon'],
            ['expire' => now()->addYears(5)->toDateString(), 'band' => 'current', 'label' => 'Current'],
            ['expire' => null, 'band' => 'current', 'label' => 'Current'],
        ];
        foreach ($cases as $c) {
            $u = User::factory()->for($org, 'organization')->create();
            Completion::factory()->for($org, 'organization')->for($u, 'user')->state([
                'module_type' => Training::class,
                'module_id' => $training->id,
                'completion_date' => '2026-01-01',
                'expire_date' => $c['expire'],
            ])->create();
        }

        $this->actingAs($manager)->get(route('reports.training-record', $training))->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) use ($cases) {
            $rows = new Collection($pdf->viewData['rows']);
            foreach ($cases as $c) {
                $hit = $rows->first(fn (array $r) => $r['status'] === $c['label'] && $r['_band'] === $c['band']);
                if ($hit === null) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_training_record_renders_even_with_no_completions(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Empty']);

        $this->actingAs($manager)
            ->get(route('reports.training-record', $training))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report' && $pdf->viewData['rows'] === [],
        );
    }

    public function test_manager_can_export_a_user_record(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'Hazwoper']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-02-01',
        ])->create();

        $this->actingAs($manager)
            ->get(route('reports.user-record', $user))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['subtitle'] === 'Lee, Sam'
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['training'] === 'Hazwoper',
                ),
        );
    }

    public function test_user_record_forbidden_for_non_manager(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->get(route('reports.user-record', $target))
            ->assertForbidden();
    }

    public function test_reports_page_shell_for_manager(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->get(route('reports.page'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('reports/Index'));
    }

    public function test_completion_report_json_filters_by_date_training_and_user(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $alice = User::factory()->for($org, 'organization')->create(['f_name' => 'Alice', 'l_name' => 'Adams']);
        $bob = User::factory()->for($org, 'organization')->create(['f_name' => 'Bob', 'l_name' => 'Baker']);
        $forklift = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift']);
        $ladders = Training::factory()->for($org, 'organization')->create(['name' => 'Ladders']);

        $mk = fn (User $u, Training $t, string $date) => Completion::factory()->for($org, 'organization')->for($u, 'user')->state([
            'module_type' => Training::class, 'module_id' => $t->id, 'completion_date' => $date,
        ])->create();
        $mk($alice, $forklift, '2026-03-01');
        $mk($bob, $ladders, '2026-01-01');

        // No filter → both.
        $all = $this->actingAs($manager)->getJson(route('reports.completions'))->assertOk()->json('data');
        $this->assertCount(2, $all);

        // Training filter.
        $byTraining = $this->actingAs($manager)->getJson(route('reports.completions', ['q' => 'forklift']))->json('data');
        $this->assertSame(['Forklift'], collect($byTraining)->pluck('training')->all());

        // User filter.
        $byUser = $this->actingAs($manager)->getJson(route('reports.completions', ['user_q' => 'baker']))->json('data');
        $this->assertSame(['Baker, Bob'], collect($byUser)->pluck('user')->all());

        // Date range (Feb–Apr) → only the March completion.
        $byDate = $this->actingAs($manager)->getJson(route('reports.completions', ['from' => '2026-02-01', 'to' => '2026-04-01']))->json('data');
        $this->assertCount(1, $byDate);
        $this->assertSame('Forklift', $byDate[0]['training']);
    }

    public function test_completion_report_json_carries_each_users_tag_ids(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $tagged = User::factory()->for($org, 'organization')->create(['f_name' => 'Tina', 'l_name' => 'Tagged']);
        $untagged = User::factory()->for($org, 'organization')->create(['f_name' => 'Una', 'l_name' => 'Untagged']);
        $tagA = Tag::factory()->for($org, 'organization')->create();
        $tagB = Tag::factory()->for($org, 'organization')->create();
        $tagged->tags()->attach([$tagA->id, $tagB->id]);

        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $mk = fn (User $u) => Completion::factory()->for($org, 'organization')->for($u, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();
        $mk($tagged);
        $mk($untagged);

        $rows = collect($this->actingAs($manager)->getJson(route('reports.completions'))->assertOk()->json('data'));

        $taggedRow = $rows->firstWhere('user', 'Tagged, Tina');
        $untaggedRow = $rows->firstWhere('user', 'Untagged, Una');

        $this->assertEqualsCanonicalizing([$tagA->id, $tagB->id], $taggedRow['tag_ids']);
        $this->assertSame($tagged->id, $taggedRow['user_id']);
        $this->assertSame([], $untaggedRow['tag_ids']);
    }

    public function test_completion_report_json_filters_by_expiry_status(): void
    {
        Carbon::setTestNow('2026-06-15'); // soonDays=30 → due-soon boundary 2026-07-15.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);

        $mk = function (string $lname, ?string $expire) use ($org, $training): void {
            $u = User::factory()->for($org, 'organization')->create(['l_name' => $lname]);
            Completion::factory()->for($org, 'organization')->for($u, 'user')->state([
                'module_type' => Training::class, 'module_id' => $training->id,
                'completion_date' => '2026-01-01', 'expire_date' => $expire,
            ])->create();
        };
        $mk('Expired', '2026-01-01');   // past → expired
        $mk('Soon', '2026-07-01');      // within window → due_soon
        $mk('Current', '2026-12-01');   // beyond window → current
        $mk('NoExpiry', null);          // null → current (never lapses)

        $statuses = fn (array $s) => collect($this->actingAs($manager)
            ->getJson(route('reports.completions', ['statuses' => $s]))->assertOk()->json('data'))
            ->pluck('status')->all();

        $this->assertSame(['Expired'], $statuses(['expired']));
        $this->assertSame(['Expires soon'], $statuses(['due_soon']));
        $this->assertEqualsCanonicalizing(['Current', 'Current'], $statuses(['current']));
        $this->assertEqualsCanonicalizing(['Expired', 'Expires soon'], $statuses(['expired', 'due_soon']));
        $this->assertCount(4, $statuses([])); // no status filter → everyone
    }

    public function test_completion_report_export_pdf(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();

        $this->actingAs($manager)
            ->get(route('reports.completions-export'))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['title'] === 'Completion report'
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam' && $r['training'] === 'CPR',
                ),
        );
    }

    public function test_completion_report_export_pdf_includes_a_tags_column(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $alpha = Tag::factory()->for($org, 'organization')->create(['name' => 'Alpha']);
        $beta = Tag::factory()->for($org, 'organization')->create(['name' => 'Beta']);
        $user->tags()->attach([$alpha->id, $beta->id]);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();

        $this->actingAs($manager)->get(route('reports.completions-export'))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => (new Collection($pdf->viewData['columns']))->contains(fn (array $c) => $c['key'] === 'tags')
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam'
                        && str_contains($r['tags'], 'Alpha')
                        && str_contains($r['tags'], 'Beta'),
                ),
        );
    }

    public function test_completion_report_export_honors_selected_columns_and_order(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();

        // Subset + reordered (status before user), one unknown key to be ignored.
        $this->actingAs($manager)
            ->get(route('reports.completions-export', ['columns' => ['status', 'user', 'bogus']]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => (new Collection($pdf->viewData['columns']))->pluck('key')->all() === ['status', 'user'],
        );
    }

    public function test_completion_report_export_defaults_to_all_columns(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();

        // No columns param → full set; all-unknown → also falls back to full set.
        foreach ([[], ['columns' => ['nope']]] as $query) {
            $this->actingAs($manager)->get(route('reports.completions-export', $query))->assertOk();
            Pdf::assertRespondedWithPdf(
                fn ($pdf) => (new Collection($pdf->viewData['columns']))->pluck('key')->all()
                    === ['user', 'employee_number', 'department', 'location', 'training', 'completion_date', 'expire_date', 'status', 'tags', 'hours', 'class', 'cert_id'],
            );
        }
    }

    public function test_completion_report_export_defaults_to_no_grouping(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
        ])->create();

        $this->actingAs($manager)->get(route('reports.completions-export'))->assertOk();

        // No group_by[] → flat report; blade gets an empty groups list.
        Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewData['groups'] === []);
    }

    public function test_completion_report_export_groups_rows_when_requested(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $yard1 = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee', 'location' => 'Yard']);
        $yard2 = User::factory()->for($org, 'organization')->create(['f_name' => 'Jane', 'l_name' => 'Doe', 'location' => 'Yard']);
        $dock = User::factory()->for($org, 'organization')->create(['f_name' => 'Max', 'l_name' => 'Roe', 'location' => 'Dock']);
        foreach ([$yard1, $yard2, $dock] as $u) {
            Completion::factory()->for($org, 'organization')->for($u, 'user')->state([
                'module_type' => Training::class, 'module_id' => $training->id, 'completion_date' => '2026-02-01',
            ])->create();
        }

        $this->actingAs($manager)
            ->get(route('reports.completions-export', ['group_by' => ['location', 'bogus']]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $groups = (new Collection($pdf->viewData['groups']))
                ->where('type', 'group')
                ->map(fn (array $g) => [$g['level'], $g['label'], $g['count']])
                ->values()
                ->all();

            // Unknown 'bogus' key dropped; grouped by location, sorted, with counts.
            return $groups === [
                [0, 'Location: Dock', 1],
                [0, 'Location: Yard', 2],
            ];
        });
    }

    public function test_completion_report_forbidden_for_non_manager(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)->getJson(route('reports.completions'))->assertForbidden();
        $this->actingAs($member)->get(route('reports.completions-export'))->assertForbidden();
    }

    public function test_non_manager_cannot_export(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->get(route('reports.training-record', $training))
            ->assertForbidden();
    }

    public function test_training_record_is_org_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        $trainingB = Training::factory()->for($orgB, 'organization')->create();

        // Cross-org training id 404s via org-scoped route binding.
        $this->actingAs($managerA)
            ->get(route('reports.training-record', $trainingB))
            ->assertNotFound();
    }
}
