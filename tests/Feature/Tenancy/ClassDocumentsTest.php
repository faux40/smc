<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Endpoint coverage for the printable class PDFs. The production cert failure
 * (memory / font cache) isn't reproducible in the test env, so these lock the
 * correct rendering path through the CSS-cert refactor: every endpoint streams
 * a valid application/pdf, no-cert classes 404, and cross-org access is denied.
 */
class ClassDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array{org: Organization, admin: User, class: TrainingClass}
     */
    private function completedClassWithCert(): array
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $student = User::factory()->for($org, 'organization')
            ->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        $training = Training::factory()->for($org, 'organization')->create(['name' => 'CPR']);
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'status' => 'completed',
            'completion_date' => '2026-01-10',
            'instructor' => 'Pat Trainer',
            'show_signature' => true,
            'total_hours' => 4,
        ]);
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'training_name' => 'CPR',
            'hours' => 4,
            'lifespan_months' => 12,
            'cert_title' => 'CPR Certification',
            'cert_text' => 'Has completed **CPR** training.',
        ]);
        Completion::factory()->for($org, 'organization')->for($student, 'user')->state([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'class_training_id' => $ct->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2027-01-10',
            'cert_id' => 'CERT-001',
        ])->create();

        return compact('org', 'admin', 'class');
    }

    private function assertPdf(TestResponse $res): void
    {
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    public function test_admin_can_download_certificates_pdf(): void
    {
        ['admin' => $admin, 'class' => $class] = $this->completedClassWithCert();

        $this->assertPdf(
            $this->actingAs($admin)->get("/api/classes/{$class->id}/certificates"),
        );
    }

    public function test_certificates_404_when_none_issued(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'scheduled']);

        $this->actingAs($admin)
            ->get("/api/classes/{$class->id}/certificates")
            ->assertNotFound();
    }

    public function test_admin_can_download_sign_in_sheet_pdf(): void
    {
        ['admin' => $admin, 'class' => $class] = $this->completedClassWithCert();

        $this->assertPdf(
            $this->actingAs($admin)->get("/api/classes/{$class->id}/sign-in-sheet"),
        );
    }

    public function test_admin_can_download_summary_pdf(): void
    {
        ['admin' => $admin, 'class' => $class] = $this->completedClassWithCert();

        $this->assertPdf(
            $this->actingAs($admin)->get("/api/classes/{$class->id}/summary"),
        );
    }

    public function test_cross_org_admin_cannot_download_certificates(): void
    {
        // The org global scope hides other-org classes entirely → 404 (the
        // record isn't even resolvable), not a 403.
        ['class' => $class] = $this->completedClassWithCert();
        $otherOrg = Organization::factory()->create();
        $otherAdmin = User::factory()->for($otherOrg, 'organization')->withRole('Admin')->create();

        $this->actingAs($otherAdmin)
            ->get("/api/classes/{$class->id}/certificates")
            ->assertNotFound();
    }
}
