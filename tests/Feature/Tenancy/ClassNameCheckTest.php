<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassNameCheck;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * The class name-check sheet: an alpha-sorted proof of every person's
 * `full_name` exactly as it will print on a certificate or a wallet card,
 * so a typo is caught before a sheet of purchased stock is committed.
 */
class ClassNameCheckTest extends TestCase
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

    /** @param array<string, mixed> $attrs */
    private function enrolled(Organization $org, TrainingClass $class, array $attrs): User
    {
        $user = User::factory()->for($org, 'organization')->create($attrs);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $user->id]);

        return $user;
    }

    private function topic(TrainingClass $class, Organization $org, string $name): ClassTraining
    {
        $training = Training::factory()->for($org, 'organization')->create(['name' => $name]);

        return ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'training_name' => $name,
        ]);
    }

    private function award(Organization $org, User $user, ClassTraining $ct): void
    {
        Completion::factory()->for($org, 'organization')->for($user, 'user')->state([
            'module_type' => Training::class,
            'module_id' => $ct->training_id,
            'class_training_id' => $ct->id,
            'completion_date' => '2026-01-10',
        ])->create();
    }

    /**
     * The printed name is `PersonName::full()` — the same string `User::name`
     * puts on a certificate and `${full_name}` puts on a card. Prefix and
     * suffix are part of it; the *sort* is last, first, middle.
     */
    public function test_lists_the_printed_full_name_sorted_by_last_first_middle(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);

        $this->enrolled($org, $class, ['f_name' => 'Ann', 'm_name' => 'Beth', 'l_name' => 'Lee']);
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'm_name' => null, 'l_name' => 'Lee']);
        $this->enrolled($org, $class, ['f_name' => 'Zoe', 'm_name' => null, 'l_name' => 'Adams']);
        $this->enrolled($org, $class, [
            'prefix_name' => 'Dr.',
            'f_name' => 'Ada',
            'm_name' => 'Augusta',
            'l_name' => 'Lovelace',
            'suffix_name' => 'III',
        ]);

        $data = ClassNameCheck::data($class->fresh());

        $this->assertSame(
            ['Zoe Adams', 'Ann Lee', 'Ann Beth Lee', 'Dr. Ada Augusta Lovelace III'],
            array_column($data['rows'], 'full_name'),
        );
        $this->assertSame(4, $data['people']);
    }

    public function test_an_open_class_lists_everyone_on_the_roster(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);
        $ct = $this->topic($class, $org, 'CPR');

        $this->enrolled($org, $class, ['f_name' => 'Pat', 'l_name' => 'Passer']);
        $absent = $this->enrolled($org, $class, ['f_name' => 'Nils', 'l_name' => 'Nocredit']);
        $this->award($org, User::where('l_name', 'Passer')->firstOrFail(), $ct);

        $data = ClassNameCheck::data($class->fresh());

        // Nobody is excluded before the class is closed — the roster is the
        // list you are proof-reading against.
        $this->assertSame(['Nils Nocredit', 'Pat Passer'], array_column($data['rows'], 'full_name'));
        $this->assertFalse($data['credited_only']);
        $this->assertNotNull($absent->id);
    }

    public function test_a_completed_class_lists_only_people_awarded_credit(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed',
            'completion_date' => '2026-01-10',
        ]);
        $ct = $this->topic($class, $org, 'CPR');

        $passer = $this->enrolled($org, $class, ['f_name' => 'Pat', 'l_name' => 'Passer']);
        $this->enrolled($org, $class, ['f_name' => 'Nils', 'l_name' => 'Nocredit']);
        $this->award($org, $passer, $ct);

        $data = ClassNameCheck::data($class->fresh());

        $this->assertSame(['Pat Passer'], array_column($data['rows'], 'full_name'));
        $this->assertTrue($data['credited_only']);
        $this->assertSame(1, $data['people']);
    }

    /**
     * Credit is per topic, but the sheet is a list of people — someone who
     * passed two topics must not be printed twice.
     */
    public function test_credit_for_two_topics_lists_the_person_once(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $cpr = $this->topic($class, $org, 'CPR');
        $firstAid = $this->topic($class, $org, 'First Aid');

        $user = $this->enrolled($org, $class, ['f_name' => 'Dee', 'l_name' => 'Double']);
        $this->award($org, $user, $cpr);
        $this->award($org, $user, $firstAid);

        $data = ClassNameCheck::data($class->fresh());

        $this->assertSame(['Dee Double'], array_column($data['rows'], 'full_name'));
        $this->assertSame(1, $data['people']);
    }

    public function test_endpoint_renders_the_report_view(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['name' => 'CPR Feb']);
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee']);

        $this->actingAs($manager)->get("/api/classes/{$class->id}/name-check")->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['rows'][0]['full_name'] === 'Ann Lee',
        );
    }

    /**
     * `pdf.report` is landscape because the reports it was built for are wide.
     * A name list is one narrow column — landscape stretches it across 11
     * inches and wastes ~40% of the rows a page could hold.
     */
    public function test_the_sheet_prints_portrait_not_the_report_default(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee']);

        $this->actingAs($manager)->get("/api/classes/{$class->id}/name-check")->assertOk();

        // assertViewHas inspects *saved* PDFs; this endpoint responds with one.
        Pdf::assertRespondedWithPdf(
            fn ($pdf) => ($pdf->viewData['pageSize'] ?? null) === '8.5in 11in',
        );
    }

    public function test_csv_export_defaults_to_the_name_column_alone(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee', 'job_title' => 'Welder']);

        $csv = $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/name-check?format=csv")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Full name', $csv);
        $this->assertStringContainsString('Ann Lee', $csv);
        $this->assertStringNotContainsString('Welder', $csv);
    }

    public function test_csv_export_honors_the_selected_columns(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, [
            'f_name' => 'Ann',
            'l_name' => 'Lee',
            'job_title' => 'Welder',
            'employee_number' => 'E-42',
        ]);

        $csv = $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/name-check?format=csv&columns[]=full_name&columns[]=job_title")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Job title', $csv);
        $this->assertStringContainsString('Welder', $csv);
        // Not selected — must not ride along.
        $this->assertStringNotContainsString('E-42', $csv);
    }

    /** The name column is the point of the sheet; it cannot be deselected. */
    public function test_the_name_column_survives_a_selection_that_omits_it(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee', 'job_title' => 'Welder']);

        $csv = $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/name-check?format=csv&columns[]=job_title")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ann Lee', $csv);
    }

    public function test_filing_stores_the_sheet_against_the_class(): void
    {
        Storage::fake('linode');
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee']);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/name-check", ['type' => 'Proof'])
            ->assertCreated();

        $this->assertDatabaseHas('attachments', [
            'attachable_id' => $class->id,
            'type' => 'Proof',
        ]);
    }

    /**
     * The viewer's Save posts the columns in the body, not the query string —
     * so the filed copy has to match what was on screen rather than quietly
     * reverting to the default.
     */
    public function test_filing_keeps_the_columns_that_were_on_screen(): void
    {
        Storage::fake('linode');
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $this->enrolled($org, $class, ['f_name' => 'Ann', 'l_name' => 'Lee', 'job_title' => 'Welder']);

        Pdf::fake();
        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/name-check", [
                'columns' => ['full_name', 'job_title'],
            ])
            ->assertCreated();

        Pdf::assertViewHas('columns', [
            ['key' => 'full_name', 'label' => 'Full name'],
            ['key' => 'job_title', 'label' => 'Job title'],
        ]);
    }

    public function test_cross_org_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = $this->manager($orgA);
        $classB = TrainingClass::factory()->for($orgB, 'organization')->create();

        $this->actingAs($managerA)
            ->get("/api/classes/{$classB->id}/name-check")
            ->assertNotFound();
    }
}
