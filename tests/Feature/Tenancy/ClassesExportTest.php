<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * PDF of the classes list — the schedule as it is on screen, on paper.
 *
 * It runs the index's own filter/sort code so the sheet cannot list a
 * different set of classes than the table it was printed from.
 */
class ClassesExportTest extends TestCase
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

    private function rowValues($pdf, string $key): Collection
    {
        return (new Collection($pdf->viewData['rows']))->pluck($key);
    }

    public function test_manager_can_export_the_classes_list(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create([
            'name' => 'June Safety Day',
            'instructor' => 'Jane Doe',
            'location' => 'Main Hall',
            'scheduled_date' => '2026-06-20',
            'status' => 'scheduled',
        ]);

        $this->actingAs($manager)->get(route('classes.export'))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['title'] === 'Classes'
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['name'] === 'June Safety Day'
                        && $r['instructor'] === 'Jane Doe'
                        && $r['location'] === 'Main Hall'
                        && $r['status'] === 'Scheduled',
                ),
        );
    }

    public function test_search_narrows_the_sheet_exactly_like_the_table(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Forklift Recert']);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'June Safety Day']);

        $this->actingAs($manager)->get(route('classes.export', ['q' => 'forklift']))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $this->rowValues($pdf, 'name')->contains('Forklift Recert')
                && ! $this->rowValues($pdf, 'name')->contains('June Safety Day'),
        );
    }

    public function test_status_filter_is_honoured(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create([
            'name' => 'Open one', 'status' => 'scheduled',
        ]);
        TrainingClass::factory()->for($org, 'organization')->create([
            'name' => 'Closed one', 'status' => 'completed', 'completion_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->get(route('classes.export', ['status' => 'completed']))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $this->rowValues($pdf, 'name')->contains('Closed one')
                && ! $this->rowValues($pdf, 'name')->contains('Open one'),
        );
    }

    public function test_sort_is_honoured_so_the_sheet_reads_in_the_screens_order(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Bravo']);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Alpha']);

        $this->actingAs($manager)
            ->get(route('classes.export', ['sort' => 'name', 'dir' => 'asc']))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $this->rowValues($pdf, 'name')->take(2)->all() === ['Alpha', 'Bravo'],
        );
    }

    public function test_column_selection_is_honoured(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Trimmed']);

        $this->actingAs($manager)
            ->get(route('classes.export', ['columns' => ['name', 'date']]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => (new Collection($pdf->viewData['columns']))->pluck('key')->all()
                === ['name', 'date'],
        );
    }

    public function test_unknown_columns_fall_back_rather_than_producing_an_empty_sheet(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Whole thing']);

        $this->actingAs($manager)
            ->get(route('classes.export', ['columns' => ['nonsense']]))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => count($pdf->viewData['columns']) > 1,
        );
    }

    public function test_it_is_scoped_to_the_actors_org(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);
        TrainingClass::factory()->for($otherOrg, 'organization')->create(['name' => 'Foreign class']);

        $this->actingAs($manager)->get(route('classes.export'))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => ! $this->rowValues($pdf, 'name')->contains('Foreign class'),
        );
    }

    public function test_counts_come_through_as_numbers_not_relations(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'Counted']);
        $training = Training::factory()->for($org, 'organization')->create();
        $class->classTrainings()->create([
            'training_id' => $training->id,
            'training_name' => $training->name,
        ]);

        $this->actingAs($manager)->get(route('classes.export'))->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => (new Collection($pdf->viewData['rows']))->contains(
                fn (array $r) => $r['name'] === 'Counted' && $r['trainings'] === 1,
            ),
        );
    }

    public function test_a_self_only_user_cannot_export(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($member)->getJson(route('classes.export'))->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('classes.export'))->assertRedirect(route('login'));
    }
}
