<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassSummary;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class ClassSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // Fake the PDF driver so the endpoint test asserts the view/response
        // without shelling out to Browsershot/Chromium (matches the other PDF
        // feature tests + keeps the suite env-independent).
        Pdf::fake();
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    public function test_data_builds_certificate_issued_rows(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeating' => true,
            'repeat_days' => 365,
        ]);
        $user = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Mark', 'l_name' => 'Bristow',
            'employee_number' => 'WVSD-002', 'location' => 'West Valley',
        ]);
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-05-13',
            'cert_id' => 'FPCP20260513-001',
            'class_training_id' => $ct->id,
        ]);

        $data = ClassSummary::data($class->fresh());

        $this->assertSame(1, $data['certificates']);
        // Certificates are grouped per training; issue + expires live once on
        // the group header, not on every row.
        $group = $data['groups'][0];
        $this->assertSame('May 13, 2026', $group['issue_date']);
        $this->assertSame('May 13, 2027', $group['expires']); // +365 days (frequency)

        $row = $group['rows'][0];
        $this->assertSame('Bristow, Mark', $row['name']);
        $this->assertSame('WVSD-002', $row['emp_number']);
        $this->assertSame('West Valley', $row['location']);
        $this->assertSame('FPCP20260513-001', $row['cert_id']);
        // The per-row date columns are gone.
        $this->assertArrayNotHasKey('issue_date', $row);
        $this->assertArrayNotHasKey('expires', $row);
    }

    public function test_rows_are_last_first_middle_initial_ordered_alphabetically(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'repeating' => true, 'repeat_days' => 365,
        ]);

        // Issued in cert order, but the roster prints alphabetically by name.
        $people = [
            ['f_name' => 'Ada', 'm_name' => 'Augusta', 'l_name' => 'Lovelace', 'cert' => 'C-1'],
            ['f_name' => 'Alan', 'm_name' => 'Mathison', 'l_name' => 'Hopper', 'cert' => 'C-2'],
            ['f_name' => 'Grace', 'm_name' => null, 'l_name' => 'Hopper', 'cert' => 'C-3'],
        ];
        foreach ($people as $p) {
            $u = User::factory()->for($org, 'organization')->create([
                'f_name' => $p['f_name'], 'm_name' => $p['m_name'], 'l_name' => $p['l_name'],
            ]);
            Completion::create([
                'org_id' => $org->id, 'user_id' => $u->id,
                'module_type' => Training::class, 'module_id' => $training->id,
                'completion_date' => '2026-05-13', 'cert_id' => $p['cert'],
                'class_training_id' => $ct->id,
            ]);
        }

        $rows = ClassSummary::data($class->fresh())['groups'][0]['rows'];

        $this->assertSame(
            ['Hopper, Alan, M', 'Hopper, Grace', 'Lovelace, Ada, A'],
            array_column($rows, 'name'),
        );
    }

    public function test_data_lists_the_class_trainings(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $class = TrainingClass::factory()->for($org, 'organization')->create();
        ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_name' => 'Fall Protection',
            'hours' => 4,
            'std_freq_name' => 'Annual',
        ]);
        ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_name' => 'First Aid',
            'hours' => 2.5,
            'std_freq_name' => null,
        ]);

        $data = ClassSummary::data($class->fresh());

        $this->assertCount(2, $data['trainings']);
        $this->assertSame('Fall Protection', $data['trainings'][0]['name']);
        $this->assertSame('4.00 hrs', $data['trainings'][0]['hours']);
        $this->assertSame('Annual', $data['trainings'][0]['frequency']);
        $this->assertSame('First Aid', $data['trainings'][1]['name']);
        $this->assertSame('2.50 hrs', $data['trainings'][1]['hours']);
        $this->assertNull($data['trainings'][1]['frequency']);
    }

    public function test_expires_prefers_the_completions_own_expire_date(): void
    {
        // Imported classes have no frequency on the topic, so expires would
        // show "—" — but the completion carries a real expiry, which wins.
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeat_days' => null,
        ]);
        $user = User::factory()->for($org, 'organization')->create();
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2027-05-01',
            'cert_id' => 'CERT-1',
            'class_training_id' => $ct->id,
        ]);

        $group = ClassSummary::data($class->fresh())['groups'][0];
        $this->assertSame('May 1, 2027', $group['expires']);
    }

    public function test_expires_is_dash_when_neither_completion_nor_lifespan_set(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'repeat_days' => null,
        ]);
        $user = User::factory()->for($org, 'organization')->create();
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => null,
            'cert_id' => 'CERT-2',
            'class_training_id' => $ct->id,
        ]);

        $group = ClassSummary::data($class->fresh())['groups'][0];
        $this->assertSame('—', $group['expires']);
    }

    public function test_groups_certificates_by_training_with_header_dates(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $class = TrainingClass::factory()->for($org, 'organization')->create();

        // Two trainings on the class, each with its own frequency + a cert.
        $fp = Training::factory()->for($org, 'organization')->create();
        $ctFp = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fp->id, 'training_name' => 'Fall Protection',
            'repeating' => true, 'repeat_days' => 365,
        ]);
        $fa = Training::factory()->for($org, 'organization')->create();
        $ctFa = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fa->id, 'training_name' => 'First Aid',
            'repeating' => true, 'repeat_days' => 30,
        ]);

        $u = User::factory()->for($org, 'organization')->create(['f_name' => 'A', 'l_name' => 'One']);
        foreach ([[$fp, $ctFp, 'FP-1'], [$fa, $ctFa, 'FA-1']] as [$t, $ct, $cert]) {
            Completion::create([
                'org_id' => $org->id, 'user_id' => $u->id,
                'module_type' => Training::class, 'module_id' => $t->id,
                'completion_date' => '2026-05-13', 'cert_id' => $cert,
                'class_training_id' => $ct->id,
            ]);
        }

        $groups = ClassSummary::data($class->fresh())['groups'];

        $this->assertCount(2, $groups);
        // Group order follows the class-trainings order.
        $this->assertSame('Fall Protection', $groups[0]['training']);
        $this->assertSame('May 13, 2027', $groups[0]['expires']); // +365 days
        $this->assertSame('First Aid', $groups[1]['training']);
        $this->assertSame('Jun 12, 2026', $groups[1]['expires']); // +30 days
    }

    public function test_header_shows_varies_when_rows_in_a_training_differ(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'repeating' => true, 'repeat_days' => 365,
        ]);

        // Two people completed the same training on different dates (imported).
        foreach ([['2026-01-10', 'C-1'], ['2026-03-20', 'C-2']] as [$date, $cert]) {
            $u = User::factory()->for($org, 'organization')->create();
            Completion::create([
                'org_id' => $org->id, 'user_id' => $u->id,
                'module_type' => Training::class, 'module_id' => $training->id,
                'completion_date' => $date, 'cert_id' => $cert,
                'class_training_id' => $ct->id,
            ]);
        }

        $group = ClassSummary::data($class->fresh())['groups'][0];
        $this->assertSame('varies', $group['issue_date']);
        $this->assertSame('varies', $group['expires']);
        $this->assertCount(2, $group['rows']);
    }

    public function test_generated_at_uses_the_display_timezone(): void
    {
        config(['app.display_timezone' => 'America/Los_Angeles']);
        Carbon::setTestNow(Carbon::parse('2026-06-14 02:00:00', 'UTC'));

        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        // 02:00 UTC on Jun 14 is 19:00 PDT on Jun 13.
        $this->assertSame('Jun 13, 2026 7:00 PM', ClassSummary::data($class->fresh())['generated_at']);

        Carbon::setTestNow();
    }

    /**
     * A two-topic class where nobody sailed through cleanly: one person fails
     * a topic and no-shows the other, one passes a topic and fails the other,
     * one misses both.
     */
    private function mixedOutcomeClass(Organization $org): TrainingClass
    {
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed', 'completion_date' => '2026-05-13',
        ]);
        $fp = Training::factory()->for($org, 'organization')->create();
        $ctFp = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fp->id, 'training_name' => 'Fall Protection',
        ]);
        $fa = Training::factory()->for($org, 'organization')->create();
        $ctFa = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fa->id, 'training_name' => 'First Aid',
        ]);

        $ames = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Dana', 'l_name' => 'Ames',
            'employee_number' => 'E-1042', 'location' => 'Yard 3',
        ]);
        $reed = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Abe', 'm_name' => 'Alan', 'l_name' => 'Reed',
        ]);
        $zeller = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Aaron', 'l_name' => 'Zeller',
        ]);

        ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $ames->id, 'status' => 'incomplete',
            'notes' => 'Failed practical',
            'results' => [$ctFp->id => 'fail', $ctFa->id => 'incomplete'],
        ]);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $reed->id, 'status' => 'partial', 'notes' => null,
            'results' => [$ctFp->id => 'pass', $ctFa->id => 'fail'],
        ]);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $zeller->id, 'status' => 'incomplete', 'notes' => null,
            'results' => [$ctFp->id => 'incomplete', $ctFa->id => 'incomplete'],
        ]);

        // Reed's pass is credited.
        Completion::create([
            'org_id' => $org->id, 'user_id' => $reed->id,
            'module_type' => Training::class, 'module_id' => $fp->id,
            'completion_date' => '2026-05-13', 'cert_id' => 'FP-1',
            'class_training_id' => $ctFp->id,
        ]);

        return $class->fresh();
    }

    public function test_failed_enrollees_are_grouped_by_training(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $groups = ClassSummary::data($this->mixedOutcomeClass($org))['failed_groups'];

        // Group order follows the class-trainings order; a training nobody
        // failed is skipped entirely.
        $this->assertCount(2, $groups);
        $this->assertSame('Fall Protection', $groups[0]['training']);
        $this->assertSame(['Ames, Dana'], array_column($groups[0]['rows'], 'name'));
        $this->assertSame('First Aid', $groups[1]['training']);
        $this->assertSame(['Reed, Abe, A'], array_column($groups[1]['rows'], 'name'));
    }

    public function test_incomplete_enrollees_are_grouped_by_training_and_ordered_by_name(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $groups = ClassSummary::data($this->mixedOutcomeClass($org))['incomplete_groups'];

        $this->assertCount(2, $groups);
        $this->assertSame('Fall Protection', $groups[0]['training']);
        $this->assertSame(['Zeller, Aaron'], array_column($groups[0]['rows'], 'name'));
        $this->assertSame('First Aid', $groups[1]['training']);
        // Alphabetical (last, first, middle) like every other printed roster.
        $this->assertSame(
            ['Ames, Dana', 'Zeller, Aaron'],
            array_column($groups[1]['rows'], 'name'),
        );
    }

    public function test_outcome_rows_carry_employee_details_and_the_close_out_notes(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $row = ClassSummary::data($this->mixedOutcomeClass($org))['failed_groups'][0]['rows'][0];

        $this->assertSame('Ames, Dana', $row['name']);
        $this->assertSame('E-1042', $row['emp_number']);
        $this->assertSame('Yard 3', $row['location']);
        $this->assertSame('Failed practical', $row['notes']);
    }

    public function test_a_credited_enrollee_is_never_listed_even_without_a_stored_result(): void
    {
        // Classes closed before per-topic results existed have an empty
        // results map — the issued certificate is the proof of a pass, so
        // holders must not be swept into Incomplete. Everyone else there is.
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed', 'completion_date' => '2026-05-13',
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'training_name' => 'Fall Protection',
        ]);

        $credited = User::factory()->for($org, 'organization')->create(['f_name' => 'Mark', 'l_name' => 'Bristow']);
        $missed = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);

        foreach ([$credited, $missed] as $u) {
            ClassEnrollment::factory()->for($class, 'trainingClass')->create([
                'user_id' => $u->id, 'status' => 'enrolled', 'results' => null,
            ]);
        }

        Completion::create([
            'org_id' => $org->id, 'user_id' => $credited->id,
            'module_type' => Training::class, 'module_id' => $training->id,
            'completion_date' => '2026-05-13', 'cert_id' => 'FP-1',
            'class_training_id' => $ct->id,
        ]);

        $data = ClassSummary::data($class->fresh());

        $this->assertSame([], $data['failed_groups']);
        $this->assertSame(
            ['Lee, Sam'],
            array_column($data['incomplete_groups'][0]['rows'], 'name'),
        );
    }

    public function test_an_uncertificated_pass_still_counts_as_credited(): void
    {
        // Re-opening a class to renumber certificates NULLs cert_id until the
        // re-close. The completion row is the credit, cert_id or not.
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed', 'completion_date' => '2026-05-13',
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'training_name' => 'Fall Protection',
        ]);
        $user = User::factory()->for($org, 'organization')->create(['f_name' => 'Mark', 'l_name' => 'Bristow']);

        ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $user->id, 'status' => 'passed',
            'results' => [$ct->id => 'pass'],
        ]);
        Completion::create([
            'org_id' => $org->id, 'user_id' => $user->id,
            'module_type' => Training::class, 'module_id' => $training->id,
            'completion_date' => '2026-05-13', 'cert_id' => null,
            'class_training_id' => $ct->id,
        ]);

        $data = ClassSummary::data($class->fresh());

        $this->assertSame([], $data['groups']); // no cert → not on the issued list
        $this->assertSame([], $data['failed_groups']);
        $this->assertSame([], $data['incomplete_groups']);
    }

    public function test_an_outcome_section_is_dropped_when_nobody_is_in_it(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed', 'completion_date' => '2026-05-13',
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'training_name' => 'Fall Protection',
        ]);
        $user = User::factory()->for($org, 'organization')->create([
            'f_name' => 'Sam', 'l_name' => 'Lee', 'employee_number' => 'E-9',
        ]);
        ClassEnrollment::factory()->for($class, 'trainingClass')->create([
            'user_id' => $user->id, 'status' => 'incomplete',
            'notes' => 'No-show', 'results' => [$ct->id => 'incomplete'],
        ]);

        $html = view('pdf.class-summary', ClassSummary::data($class->fresh()))->render();

        // Nobody failed — that heading is gone entirely rather than printing
        // an empty section.
        $this->assertStringNotContainsString('Failed', $html);
        $this->assertStringContainsString('Incomplete', $html);
        $this->assertStringContainsString('Lee, Sam', $html);
        $this->assertStringContainsString('No-show', $html);
    }

    public function test_endpoint_returns_a_pdf(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)->get("/api/classes/{$class->id}/summary")->assertOk();

        Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.class-summary');
    }
}
