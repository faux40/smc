<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use App\Support\ClassSummary;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassSummaryTest extends TestCase
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

    public function test_data_builds_certificate_issued_rows(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'lifespan_months' => 36,
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
        $row = $data['rows'][0];
        $this->assertSame('Bristow, Mark', $row['name']);
        $this->assertSame('WVSD-002', $row['emp_number']);
        $this->assertSame('West Valley', $row['location']);
        $this->assertSame('FPCP20260513-001', $row['cert_id']);
        $this->assertSame('May 13, 2026', $row['issue_date']);
        $this->assertSame('May 13, 2029', $row['expires']); // +36 months
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

    public function test_endpoint_returns_a_pdf(): void
    {
        $org = Organization::factory()->create();
        $manager = $this->manager($org);
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($manager)->get("/api/classes/{$class->id}/summary");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
