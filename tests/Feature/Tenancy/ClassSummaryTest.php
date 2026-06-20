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
use Illuminate\Support\Carbon;
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
        // Certificates are grouped per training; issue + expires live once on
        // the group header, not on every row.
        $group = $data['groups'][0];
        $this->assertSame('May 13, 2026', $group['issue_date']);
        $this->assertSame('May 13, 2029', $group['expires']); // +36 months

        $row = $group['rows'][0];
        $this->assertSame('Bristow, Mark', $row['name']);
        $this->assertSame('WVSD-002', $row['emp_number']);
        $this->assertSame('West Valley', $row['location']);
        $this->assertSame('FPCP20260513-001', $row['cert_id']);
        // The per-row date columns are gone.
        $this->assertArrayNotHasKey('issue_date', $row);
        $this->assertArrayNotHasKey('expires', $row);
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
        // Imported classes have no lifespan_months on the topic, so expires
        // showed "—" even though the completion carries a real expiry.
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
            'lifespan_months' => null,
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
            'lifespan_months' => null,
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

        // Two trainings on the class, each with its own lifespan + a cert.
        $fp = Training::factory()->for($org, 'organization')->create();
        $ctFp = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fp->id, 'training_name' => 'Fall Protection', 'lifespan_months' => 12,
        ]);
        $fa = Training::factory()->for($org, 'organization')->create();
        $ctFa = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $fa->id, 'training_name' => 'First Aid', 'lifespan_months' => 24,
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
        $this->assertSame('May 13, 2027', $groups[0]['expires']); // +12mo
        $this->assertSame('First Aid', $groups[1]['training']);
        $this->assertSame('May 13, 2028', $groups[1]['expires']); // +24mo
    }

    public function test_header_shows_varies_when_rows_in_a_training_differ(): void
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id, 'lifespan_months' => 12,
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
