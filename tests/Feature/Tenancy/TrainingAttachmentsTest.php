<?php

namespace Tests\Feature\Tenancy;

use App\Models\Attachment;
use App\Models\Organization;
use App\Models\Training;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Supporting material on a training — presentation, handouts, checklists, test
 * forms. The generic attachment plumbing already accepted `Training`; what was
 * missing was the presentation formats, a role gate, and any UI.
 *
 * The gate: reading is open to any org member (an instructor needs the
 * handouts), but writing follows the training itself, which is Owner/SA/Admin
 * managed. Classes stay open to everyone — that is deliberate and pinned here.
 */
class TrainingAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    private function upload(User $actor, Training $training, string $filename = 'deck.pptx')
    {
        return $this->actingAs($actor)->post('/api/attachments', [
            'attachable_type' => Training::class,
            'attachable_id' => $training->id,
            'file' => UploadedFile::fake()->create($filename, 64),
        ], ['Accept' => 'application/json']);
    }

    public function test_presentation_formats_are_accepted(): void
    {
        // "The training presentation" is the headline use case; pptx/odp were
        // not on the allowlist, so it would have been rejected outright.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        foreach (['pptx', 'ppt', 'odp', 'odt', 'ods'] as $ext) {
            $this->upload($admin, $training, "material.{$ext}")
                ->assertCreated();
        }
    }

    public function test_a_real_pptx_and_odp_survive_magic_byte_sniffing(): void
    {
        // The tests above upload UploadedFile::fake(), which derives its MIME
        // from the *filename* — so they prove the allowlist changed, not that a
        // genuine deck gets through. The `mimes` rule validates the **sniffed**
        // type, and both formats are ZIP containers: libmagic only reports
        // OOXML when the package carries a real `[Content_Types].xml` (with the
        // presentationml override) and `_rels/.rels`; a barer zip comes back as
        // `application/zip` and would be refused. Verified against a genuine
        // PowerPoint file, which sniffs correctly — so production is fine and
        // it is the *fixture* that has to be representative.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        foreach ([$this->sniffablePptx(), $this->sniffableOdp()] as $path) {
            $this->actingAs($admin)->post('/api/attachments', [
                'attachable_type' => Training::class,
                'attachable_id' => $training->id,
                'file' => new UploadedFile($path, basename($path), null, null, true),
            ], ['Accept' => 'application/json'])
                ->assertCreated();
        }
    }

    /**
     * A minimal pptx that libmagic actually identifies as presentationml.
     * `BuildsPresentationFixtures::makePptxFixture()` is built for ZipArchive
     * structure checks (slide counting, page size) and sniffs as a plain zip,
     * which is fine there and useless here.
     */
    private function sniffablePptx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deck').'.pptx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'</Relationships>');
        $zip->addFromString('ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');
        $zip->close();

        return $path;
    }

    /**
     * A minimal odp that libmagic identifies. ODF requires `mimetype` to be the
     * first entry and **stored uncompressed** — that is precisely what the
     * sniffer reads. `makeOdpFixture()` deflates it (harmless for the
     * ZipArchive-based checks it serves), so it comes back as a plain zip.
     */
    private function sniffableOdp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'deck').'.odp';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        $zip->addFromString('content.xml', '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>');
        $zip->close();

        return $path;
    }

    public function test_previously_allowed_extensions_still_work(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        foreach (['pdf', 'docx', 'xlsx', 'png', 'txt'] as $ext) {
            $this->upload($admin, $training, "material.{$ext}")->assertCreated();
        }
    }

    public function test_script_capable_uploads_are_still_refused(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->upload($admin, $training, 'evil.svg')->assertStatus(422);
        $this->upload($admin, $training, 'evil.html')->assertStatus(422);
    }

    public function test_admin_can_upload_to_a_training(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->upload($admin, $training)->assertCreated();

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => Training::class,
            'attachable_id' => $training->id,
        ]);
    }

    public function test_ordinary_member_cannot_upload_to_a_training(): void
    {
        // The training library is Owner/SA/Admin managed; its supporting
        // material follows it rather than being open to the whole org.
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->upload($member, $training)->assertForbidden();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_manager_cannot_upload_to_a_training(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $training = Training::factory()->for($org, 'organization')->create();

        $this->upload($manager, $training)->assertForbidden();
    }

    public function test_ordinary_member_can_still_upload_to_a_class(): void
    {
        // Regression guard: the new gate is scoped to trainings. Class files
        // (sign-in sheets and the like) stay open to any org member.
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();

        $this->actingAs($member)->post('/api/attachments', [
            'attachable_type' => TrainingClass::class,
            'attachable_id' => $class->id,
            'file' => UploadedFile::fake()->create('signin.pdf', 32),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_ordinary_member_can_read_a_trainings_files(): void
    {
        // An instructor needs the handouts even though they can't manage them.
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $this->upload($admin, $training)->assertCreated();

        $this->actingAs($member)
            ->getJson('/api/attachments?attachable_type='.urlencode(Training::class).'&attachable_id='.$training->id)
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_ordinary_member_cannot_edit_training_file_metadata(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $training = Training::factory()->for($org, 'organization')->create();
        $this->upload($admin, $training)->assertCreated();
        $attachment = Attachment::firstOrFail();

        $this->actingAs($member)
            ->patchJson("/api/attachments/{$attachment->id}", ['type' => 'Handout'])
            ->assertForbidden();
    }

    public function test_cross_org_training_upload_is_refused(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $foreign = Training::factory()->for($otherOrg, 'organization')->create();

        $this->upload($admin, $foreign)->assertForbidden();
    }
}
