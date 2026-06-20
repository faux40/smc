<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\CertificateData;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class ClassCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function manager(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->withRole('Manager')->create();
    }

    public function test_rows_builds_a_view_model_per_issued_completion(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => 'Jane Doe',
            'show_signature' => true,
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'training_name' => 'Fall Protection',
            'cert_title' => 'FP Authorized Person',
            'cert_text' => "Satisfies **Cal/OSHA**\n\nSecond paragraph",
            'repeating' => true,
            'repeat_days' => 365,
            'hours' => 4,
        ]);
        $student = User::factory()->for($org, 'organization')->create(['f_name' => 'Greg', 'l_name' => 'Ange']);

        Completion::create([
            'org_id' => $org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_id' => 'FPAP20260601-001',
            'class_training_id' => $ct->id,
        ]);

        $rows = CertificateData::forClass($class->fresh());

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('FPAP20260601-001', $row['cert_id']);
        $this->assertSame('FP Authorized Person', $row['cert_title']);
        $this->assertSame('Greg Ange', $row['student_name']);
        $this->assertSame('June 1, 2026', $row['issue_date']);
        $this->assertSame('June 1, 2027', $row['expires']); // issue + 365 days (frequency)
        $this->assertSame('4.00', $row['hours']);
        $this->assertSame('Jane Doe', $row['trainer']);
        $this->assertTrue($row['show_signature']); // class-level flag
        // cert_text is rendered as Markdown → HTML (bold + paragraphs).
        $this->assertStringContainsString('<strong>Cal/OSHA</strong>', $row['cert_html']);
        $this->assertStringContainsString('Second paragraph', $row['cert_html']);
    }

    public function test_a_single_newline_in_cert_text_becomes_a_line_break(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'cert_text' => "Line one\nLine two",
        ]);
        $student = User::factory()->for($org, 'organization')->create();

        Completion::create([
            'org_id' => $org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_id' => 'X-1',
            'class_training_id' => $ct->id,
        ]);

        $row = CertificateData::forClass($class->fresh())[0];

        // A single newline → <br> (soft_break renderer), not a collapsed space.
        $this->assertStringContainsString('<br', $row['cert_html']);
        $this->assertStringContainsString('Line one', $row['cert_html']);
        $this->assertStringContainsString('Line two', $row['cert_html']);
    }

    public function test_for_completion_pulls_cert_content_from_the_class_snapshot(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create([
            // Training-level content the snapshot intentionally diverges from.
            'cert_title' => 'Training-level Title',
            'cert_text' => 'Training-level text',
        ]);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'instructor' => 'Jane Doe',
            'show_signature' => true,
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'training_name' => 'Fall Protection',
            'cert_title' => 'Class-overridden Title',
            'cert_text' => 'Class-overridden **text**',
            'repeating' => true,
            'repeat_days' => 365,
            'hours' => 4,
        ]);
        $student = User::factory()->for($org, 'organization')->create(['f_name' => 'Greg', 'l_name' => 'Ange']);

        $comp = Completion::create([
            'org_id' => $org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_id' => 'FPAP20260601-001',
            'class_training_id' => $ct->id,
        ]);

        $rows = CertificateData::forCompletion($comp);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        // Class snapshot wins over the training when the completion came from a class.
        $this->assertSame('Class-overridden Title', $row['cert_title']);
        $this->assertStringContainsString('<strong>text</strong>', $row['cert_html']);
        $this->assertSame('FPAP20260601-001', $row['cert_id']);
        $this->assertSame('Greg Ange', $row['student_name']);
        $this->assertSame('Jane Doe', $row['trainer']);
        $this->assertTrue($row['show_signature']);
        $this->assertSame('June 1, 2027', $row['expires']); // issue + 365 days (frequency)
    }

    public function test_for_completion_falls_back_to_the_training_when_not_from_a_class(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        // A live training's lifespan is its frequency (repeat_days via std_freq).
        $annual = StdFrequency::create([
            'org_id' => $org->id, 'name' => 'Annual', 'repeat_days' => 365,
        ]);
        $training = Training::factory()->for($org, 'organization')->create([
            'name' => 'CPR Basics',
            'cert_title' => 'CPR Certified',
            'cert_text' => 'Completed *CPR* training',
            'repeating' => true,
            'std_freq_id' => $annual->id,
        ]);
        $student = User::factory()->for($org, 'organization')->create(['f_name' => 'Pat', 'l_name' => 'Lee']);

        // A manual / imported completion: no class_training_id.
        $comp = Completion::create([
            'org_id' => $org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_ident' => 'EXT-12345',
        ]);

        $rows = CertificateData::forCompletion($comp);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        // Resolved from the training, since there's no class snapshot.
        $this->assertSame('CPR Certified', $row['cert_title']);
        $this->assertStringContainsString('<em>CPR</em>', $row['cert_html']);
        $this->assertSame('Pat Lee', $row['student_name']);
        $this->assertSame('EXT-12345', $row['cert_id']);   // falls back to cert_ident
        $this->assertSame('June 1, 2027', $row['expires']); // issue + 12mo
        $this->assertNull($row['trainer']);                 // no class → no instructor
        $this->assertFalse($row['show_signature']);
    }

    public function test_endpoint_returns_a_pdf_for_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'cert_code' => 'FPAP',
        ]);
        $student = User::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $student->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [['id' => $enrollment->id, 'results' => [['class_training_id' => $ct->id, 'passed' => true]]]],
        ])->assertOk();

        Pdf::fake();
        $this->actingAs($manager)->get("/api/classes/{$class->id}/certificates")->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.certificate'
                && array_key_exists('certs', $pdf->viewData),
        );
    }

    public function test_endpoint_404s_when_no_certificates(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->get("/api/classes/{$class->id}/certificates")
            ->assertNotFound();
    }

    public function test_completion_certificate_endpoint_returns_a_pdf(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create([
            'cert_title' => 'CPR Certified',
            'cert_text' => 'Completed CPR',
        ]);
        $student = User::factory()->for($org, 'organization')->create();

        $comp = Completion::create([
            'org_id' => $org->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_ident' => 'EXT-1',
        ]);

        Pdf::fake();
        $this->actingAs($manager)->get("/api/completions/{$comp->id}/certificate")->assertOk();

        Pdf::assertRespondedWithPdf(fn ($pdf) => $pdf->viewName === 'pdf.certificate');
    }

    public function test_completion_certificate_endpoint_blocks_a_cross_org_completion(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $manager = $this->manager($org);

        $training = Training::factory()->for($otherOrg, 'organization')->create();
        $student = User::factory()->for($otherOrg, 'organization')->create();
        $comp = Completion::create([
            'org_id' => $otherOrg->id,
            'user_id' => $student->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-06-01',
            'cert_ident' => 'EXT-2',
        ]);

        $this->actingAs($manager)
            ->get("/api/completions/{$comp->id}/certificate")
            ->assertNotFound();
    }
}
