<?php

namespace Tests\Feature\Tenancy;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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
        $student = User::factory()->for($org, 'organization')->create(['f_name' => 'Sam', 'l_name' => 'Lee']);
        Completion::factory()->for($org, 'organization')->for($student, 'user')->state([
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-01-10',
            'expire_date' => '2027-01-10',
            'cert_id' => 'CERT-9',
        ])->create();

        $this->actingAs($manager)
            ->get(route('reports.training-record', $training))
            ->assertOk();

        Pdf::assertRespondedWithPdf(
            fn ($pdf) => $pdf->viewName === 'pdf.report'
                && $pdf->viewData['subtitle'] === 'CPR'
                && (new Collection($pdf->viewData['rows']))->contains(
                    fn (array $r) => $r['user'] === 'Lee, Sam' && $r['cert_id'] === 'CERT-9',
                ),
        );
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
