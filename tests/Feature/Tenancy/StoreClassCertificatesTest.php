<?php

namespace Tests\Feature\Tenancy;

use App\Actions\StoreClassCertificates;
use App\Events\AttachmentCreated;
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
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\TestCase;

class StoreClassCertificatesTest extends TestCase
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

    /**
     * A completed class with one issued certificate.
     *
     * @return array{org: Organization, class: TrainingClass}
     */
    private function classWithCert(string $className): array
    {
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);

        $training = Training::factory()->for($org, 'organization')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create([
            'name' => $className,
            'status' => 'completed',
        ]);
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

        return ['org' => $org, 'class' => $class];
    }

    public function test_files_a_certificate_pdf_as_a_class_attachment(): void
    {
        Pdf::fake();
        Event::fake([AttachmentCreated::class]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 08:15:00', config('app.display_timezone')));

        ['org' => $org, 'class' => $class] = $this->classWithCert('Fall Protection Refresher');
        $this->actingAs($this->manager($org));

        $attachment = (new StoreClassCertificates)->handle($class->fresh());

        $this->assertInstanceOf(Attachment::class, $attachment);
        $this->assertSame(TrainingClass::class, $attachment->attachable_type);
        $this->assertSame($class->id, $attachment->attachable_id);
        $this->assertSame($org->id, $attachment->org_id);
        $this->assertSame('linode', $attachment->disk);
        $this->assertSame('application/pdf', $attachment->mime);
        // Underscored class name + date + time.
        $this->assertSame(
            'Certificates_Fall_Protection_Refresher_20260620_0815.pdf',
            $attachment->filename,
        );
        $this->assertStringStartsWith('attachments/', $attachment->path);

        Pdf::assertSaved(fn ($pdf, string $path) => $path === $attachment->path);
        Event::assertDispatched(AttachmentCreated::class);

        Carbon::setTestNow();
    }

    public function test_each_save_files_a_new_timestamped_copy(): void
    {
        Pdf::fake();
        ['org' => $org, 'class' => $class] = $this->classWithCert('CPR');
        $this->actingAs($this->manager($org));

        (new StoreClassCertificates)->handle($class->fresh());
        (new StoreClassCertificates)->handle($class->fresh());

        $this->assertSame(2, Attachment::where('attachable_id', $class->id)->count());
    }

    public function test_aborts_when_the_class_has_no_issued_certificates(): void
    {
        Pdf::fake();
        $org = Organization::factory()->create();
        app()->instance('currentOrgId', $org->id);
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $this->actingAs($this->manager($org));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new StoreClassCertificates)->handle($class->fresh());

        $this->assertSame(0, Attachment::count());
    }

    public function test_endpoint_files_the_certificate(): void
    {
        Pdf::fake();
        ['org' => $org, 'class' => $class] = $this->classWithCert('Hazwoper');
        $manager = $this->manager($org);

        $this->actingAs($manager)
            ->postJson("/api/classes/{$class->id}/certificates")
            ->assertCreated()
            ->assertJsonStructure(['id', 'filename']);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $class->id,
            'disk' => 'linode',
            'mime' => 'application/pdf',
        ]);
    }
}
