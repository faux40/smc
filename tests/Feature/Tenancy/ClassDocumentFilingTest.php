<?php

namespace Tests\Feature\Tenancy;

use App\Actions\FileClassDocument;
use App\Models\Attachment;
use App\Models\ClassTraining;
use App\Models\Completion;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

/**
 * Filing generated class documents (certificates, summary) as copies in the
 * class's attachments.
 */
class ClassDocumentFilingTest extends TestCase
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

    private function completedClass(Organization $org, string $name): TrainingClass
    {
        return TrainingClass::factory()->for($org, 'organization')->create([
            'name' => $name,
            'status' => 'completed',
        ]);
    }

    private function issueCert(Organization $org, TrainingClass $class): void
    {
        $training = Training::factory()->for($org, 'organization')->create();
        $ct = ClassTraining::factory()->for($class, 'trainingClass')->create([
            'training_id' => $training->id,
        ]);
        $user = User::factory()->for($org, 'organization')->create();
        Completion::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'module_type' => Training::class,
            'module_id' => $training->id,
            'completion_date' => '2026-05-13',
            'cert_id' => 'FPCP20260513-001',
            'class_training_id' => $ct->id,
        ]);
    }

    public function test_filename_uses_prefix_class_and_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 08:15:00', config('app.display_timezone')));
        $org = Organization::factory()->create();
        $class = $this->completedClass($org, 'Fall Protection Refresher');

        $this->assertSame(
            'Certificates_Fall_Protection_Refresher_20260620_0815.pdf',
            FileClassDocument::filename($class, 'Certificates'),
        );
        $this->assertSame(
            'Summary_Fall_Protection_Refresher_20260620_0815.pdf',
            FileClassDocument::filename($class, 'Summary'),
        );

        Carbon::setTestNow();
    }

    public function test_certificates_endpoint_files_a_pdf(): void
    {
        Pdf::fake();
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = $this->completedClass($org, 'Hazwoper');
        $this->issueCert($org, $class);

        $this->actingAs($this->manager($org))
            ->postJson("/api/classes/{$class->id}/certificates")
            ->assertCreated()
            ->assertJsonStructure(['id', 'filename']);

        $row = Attachment::where('attachable_id', $class->id)->firstOrFail();
        $this->assertSame(TrainingClass::class, $row->attachable_type);
        $this->assertSame('linode', $row->disk);
        $this->assertSame('application/pdf', $row->mime);
        $this->assertStringStartsWith('Certificates_Hazwoper_', $row->filename);
    }

    public function test_summary_endpoint_files_a_pdf(): void
    {
        Pdf::fake();
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = $this->completedClass($org, 'Hazwoper');

        $this->actingAs($this->manager($org))
            ->postJson("/api/classes/{$class->id}/summary")
            ->assertCreated();

        $row = Attachment::where('attachable_id', $class->id)->firstOrFail();
        $this->assertSame('application/pdf', $row->mime);
        $this->assertStringStartsWith('Summary_Hazwoper_', $row->filename);
    }

    public function test_certificates_endpoint_422_when_none_issued(): void
    {
        Pdf::fake();
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = $this->completedClass($org, 'Empty');

        $this->actingAs($this->manager($org))
            ->postJson("/api/classes/{$class->id}/certificates")
            ->assertStatus(422);

        $this->assertSame(0, Attachment::count());
    }

    public function test_each_save_files_a_new_copy(): void
    {
        Pdf::fake();
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = $this->completedClass($org, 'Summary Class');
        $manager = $this->manager($org);

        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/summary")->assertCreated();
        $this->actingAs($manager)->postJson("/api/classes/{$class->id}/summary")->assertCreated();

        $this->assertSame(2, Attachment::where('attachable_id', $class->id)->count());
    }
}
