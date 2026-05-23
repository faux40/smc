<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassEnrollment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassCertificates;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'lifespan_months' => 24,
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

        $rows = ClassCertificates::rows($class->fresh());

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('FPAP20260601-001', $row['cert_id']);
        $this->assertSame('FP Authorized Person', $row['cert_title']);
        $this->assertSame('Greg Ange', $row['student_name']);
        $this->assertSame('June 1, 2026', $row['issue_date']);
        $this->assertSame('June 1, 2028', $row['expires']); // issue + 24 months
        $this->assertSame('4.00', $row['hours']);
        $this->assertSame('Jane Doe', $row['trainer']);
        $this->assertTrue($row['show_signature']); // class-level flag
        // cert_text is rendered as Markdown → HTML (bold + paragraphs).
        $this->assertStringContainsString('<strong>Cal/OSHA</strong>', $row['cert_html']);
        $this->assertStringContainsString('Second paragraph', $row['cert_html']);
    }

    public function test_endpoint_returns_a_pdf_for_a_completed_class(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'cert_code' => 'FPAP',
        ]);
        $student = User::factory()->for($org, 'organization')->create();
        $enrollment = ClassEnrollment::factory()->for($class, 'trainingClass')->create(['user_id' => $student->id]);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/complete", [
            'completion_date' => '2026-06-01',
            'enrollments' => [['id' => $enrollment->id, 'status' => 'passed']],
        ])->assertOk();

        $response = $this->actingAs($manager)->get("/api/classes/{$class->id}/certificates");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
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
}
