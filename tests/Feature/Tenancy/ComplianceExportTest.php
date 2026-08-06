<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Requirement;
use App\Models\Training;
use App\Models\TrainingAssignment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * PDFs of the compliance roll-ups — one per tab.
 *
 * Each runs the tab's own ComplianceQuery method so the sheet cannot roll up
 * differently from the table. The bucket counts arrive nested under `counts`
 * for the screen, so the export flattens them: pdf.report reads flat keys.
 */
class ComplianceExportTest extends TestCase
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

    private function assign(
        Organization $org,
        User $user,
        Training $training,
        string $status,
    ): TrainingAssignment {
        return TrainingAssignment::factory()->for($org, 'organization')->create([
            'user_id' => $user->id,
            'training_id' => $training->id,
            'name' => $training->name,
            'status' => $status,
        ]);
    }

    private function rows($pdf): Collection
    {
        return new Collection($pdf->viewData['rows']);
    }

    public function test_by_training_tab_exports_flat_bucket_counts(): void
    {
        // The heart of it: aggregate() nests counts under `counts`, while
        // pdf.report reads $row[$col['key']]. Un-flattened, every bucket
        // column would print blank while the sheet still looked plausible.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $a = User::factory()->for($org, 'organization')->create();
        $b = User::factory()->for($org, 'organization')->create();
        $this->assign($org, $a, $training, 'overdue');
        $this->assign($org, $b, $training, 'current');

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'training']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['title'] === 'Compliance — by training'
                && $this->rows($pdf)->contains(
                    fn (array $r) => $r['name'] === 'CPR'
                        && $r['overdue'] === 1
                        && $r['current'] === 1
                        && $r['total'] === 2,
                ),
        );
    }

    public function test_by_requirement_tab_exports(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        Requirement::factory()->for($org, 'organization')->create(['name' => 'Confined Space']);

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'requirement']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewData['title'] === 'Compliance — by requirement',
        );
    }

    public function test_not_required_tab_uses_its_own_two_bucket_catalog(): void
    {
        // This tab counts Current / Expired only — the five-bucket catalog
        // would print three permanently empty columns.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'not-required']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(function ($pdf) {
            $keys = (new Collection($pdf->viewData['columns']))->pluck('key')->all();

            return in_array('current', $keys, true)
                && in_array('expired', $keys, true)
                && ! in_array('due_soon', $keys, true)
                && ! in_array('not_started', $keys, true);
        });
    }

    public function test_search_narrows_the_sheet(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create();
        $cpr = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $forklift = Training::factory()->for($org, 'organization')->create(['name' => 'Forklift']);
        $this->assign($org, $user, $cpr, 'overdue');
        $this->assign($org, $user, $forklift, 'overdue');

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'training', 'q' => 'forklift']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $this->rows($pdf)->pluck('name')->contains('Forklift')
                && ! $this->rows($pdf)->pluck('name')->contains('CPR'),
        );
    }

    public function test_sort_is_honoured(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create();
        $alpha = Training::factory()->for($org, 'organization')->create(['name' => 'Alpha']);
        $bravo = Training::factory()->for($org, 'organization')->create(['name' => 'Bravo']);
        $this->assign($org, $user, $alpha, 'current');
        $this->assign($org, $user, $bravo, 'current');

        $this->actingAs($manager)
            ->get(route('compliance.export', [
                'dimension' => 'training', 'sort' => 'name', 'dir' => 'asc',
            ]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $this->rows($pdf)->pluck('name')->take(2)->all() === ['Alpha', 'Bravo'],
        );
    }

    public function test_column_selection_is_honoured(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->get(route('compliance.export', [
                'dimension' => 'training', 'columns' => ['name', 'overdue'],
            ]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => (new Collection($pdf->viewData['columns']))->pluck('key')->all()
                === ['name', 'overdue'],
        );
    }

    public function test_the_export_is_not_limited_to_one_page_of_results(): void
    {
        // aggregate() always paginates at <=100; an export that inherited that
        // would silently print the first page and look complete.
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $user = User::factory()->for($org, 'organization')->create();

        foreach (range(1, 30) as $i) {
            $training = Training::factory()->for($org, 'organization')->create([
                'name' => sprintf('Training %02d', $i),
            ]);
            $this->assign($org, $user, $training, 'overdue');
        }

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'training', 'per_page' => 10]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(fn ($pdf) => count($pdf->viewData['rows']) === 30);
    }

    public function test_an_unknown_dimension_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->getJson(route('compliance.export', ['dimension' => 'nonsense']))
            ->assertStatus(422);
    }

    public function test_it_is_scoped_to_the_actors_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        $foreignUser = User::factory()->for($otherOrg, 'organization')->create();
        $foreign = Training::factory()->for($otherOrg, 'organization')->create(['name' => 'Foreign only']);
        $this->assign($otherOrg, $foreignUser, $foreign, 'overdue');

        $this->actingAs($manager)
            ->get(route('compliance.export', ['dimension' => 'training']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => ! $this->rows($pdf)->pluck('name')->contains('Foreign only'),
        );
    }

    public function test_a_self_only_user_cannot_export(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)
            ->getJson(route('compliance.export', ['dimension' => 'training']))
            ->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('compliance.export', ['dimension' => 'training']))
            ->assertRedirect(route('login'));
    }
}
