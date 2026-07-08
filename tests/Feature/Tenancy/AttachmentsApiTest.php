<?php

namespace Tests\Feature\Tenancy;

use App\Events\AttachmentCreated;
use App\Events\AttachmentDeleted;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\TrainingClass;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AttachmentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    private function indexUrl(User $target): string
    {
        return '/api/attachments?'.http_build_query([
            'attachable_type' => User::class,
            'attachable_id' => $target->id,
        ]);
    }

    public function test_anyone_in_org_can_list_attachments(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'cert.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'disk' => 'linode',
            'path' => 'attachments/abc.pdf',
        ]);

        $this->actingAs($member)
            ->getJson($this->indexUrl($target))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_list_cross_org_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($userA)
            ->getJson($this->indexUrl($targetB))
            ->assertForbidden();
    }

    public function test_anyone_in_org_can_upload(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create('cert.pdf', 256, 'application/pdf');

        $response = $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $target->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $row = Attachment::firstOrFail();
        $this->assertSame($uploader->id, $row->uploaded_by_user_id);
        $this->assertSame($org->id, $row->org_id);
        $this->assertSame('cert.pdf', $row->filename);
        $this->assertSame('linode', $row->disk);
        Storage::disk('linode')->assertExists($row->path);
    }

    public function test_upload_persists_optional_type_and_description(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create('roster.pdf', 64, 'application/pdf');

        $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $target->id,
                'file' => $file,
                'type' => 'Sign-in sheet',
                'description' => 'Morning session roster',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $row = Attachment::firstOrFail();
        $this->assertSame('Sign-in sheet', $row->type);
        $this->assertSame('Morning session roster', $row->description);

        // The list exposes them.
        $this->actingAs($uploader)
            ->getJson($this->indexUrl($target))
            ->assertOk()
            ->assertJsonPath('0.type', 'Sign-in sheet')
            ->assertJsonPath('0.description', 'Morning session roster');
    }

    public function test_org_member_can_edit_type_and_description_before_close(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $att = Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $member->id, 'type' => null, 'description' => null,
        ]);

        $this->actingAs($member)
            ->patchJson("/api/attachments/{$att->id}", [
                'type' => 'Test', 'description' => 'updated',
            ])
            ->assertOk()
            ->assertJsonPath('type', 'Test')
            ->assertJsonPath('description', 'updated');

        $this->assertSame('Test', $att->fresh()->type);
    }

    public function test_editing_metadata_on_a_completed_class_requires_admin(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create(['status' => 'completed']);
        $att = Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => TrainingClass::class, 'attachable_id' => $class->id,
            'uploaded_by_user_id' => $manager->id,
        ]);

        // Manager (the uploader) is blocked once the class is closed.
        $this->actingAs($manager)
            ->patchJson("/api/attachments/{$att->id}", ['type' => 'Nope'])
            ->assertForbidden();

        // Admin may edit after close.
        $this->actingAs($admin)
            ->patchJson("/api/attachments/{$att->id}", ['type' => 'Sign-in sheet'])
            ->assertOk();

        $this->assertSame('Sign-in sheet', $att->fresh()->type);
    }

    public function test_index_reports_can_edit(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $member->id,
        ]);

        $this->actingAs($member)
            ->getJson($this->indexUrl($target))
            ->assertOk()
            ->assertJsonPath('0.can_edit', true);
    }

    public function test_types_endpoint_returns_distinct_org_scoped_types(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $user->id, 'type' => 'Test',
        ]);
        Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $user->id, 'type' => 'Sign-in sheet',
        ]);
        // Duplicate type → collapses; null type → excluded.
        Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $user->id, 'type' => 'Test',
        ]);
        Attachment::factory()->for($org, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $target->id,
            'uploaded_by_user_id' => $user->id, 'type' => null,
        ]);
        // Another org's type must not leak.
        $otherUser = User::factory()->for($other, 'organization')->create();
        Attachment::factory()->for($other, 'organization')->create([
            'attachable_type' => User::class, 'attachable_id' => $otherUser->id,
            'uploaded_by_user_id' => $otherUser->id, 'type' => 'Other-Org Type',
        ]);

        $this->actingAs($user)
            ->getJson('/api/attachments/types')
            ->assertOk()
            ->assertExactJson(['Sign-in sheet', 'Test']); // distinct, sorted
    }

    public function test_upload_rejects_cross_org_morphable(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $uploaderA = User::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();
        $file = UploadedFile::fake()->create('x.pdf', 32);

        $this->actingAs($uploaderA)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $targetB->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_upload_rejects_unknown_attachable_type(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create('x.pdf', 32);

        $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => 'App\\Models\\Organization',
                'attachable_id' => $org->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_uploader_can_delete_own_attachment(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/own.pdf', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $uploader->id,
            'filename' => 'own.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/own.pdf',
        ]);

        $this->actingAs($uploader)
            ->deleteJson("/api/attachments/{$att->id}")
            ->assertOk();

        $this->assertSoftDeleted('attachments', ['id' => $att->id]);
    }

    public function test_admin_can_delete_any_attachment(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $uploader->id,
            'filename' => 'a.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/a.pdf',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/attachments/{$att->id}")
            ->assertOk();

        $this->assertSoftDeleted('attachments', ['id' => $att->id]);
    }

    public function test_non_uploader_non_admin_cannot_delete(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $other = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $uploader->id,
            'filename' => 'a.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/a.pdf',
        ]);

        $this->actingAs($other)
            ->deleteJson("/api/attachments/{$att->id}")
            ->assertForbidden();
    }

    public function test_download_redirects_to_disk_url_for_org_member(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/d.pdf', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'd.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/d.pdf',
        ]);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/download")
            ->assertRedirect();
    }

    public function test_view_redirects_for_org_member(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/v.pdf', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'v.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/v.pdf',
        ]);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/view")
            ->assertRedirect();
    }

    public function test_view_cross_org_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $uploaderB = User::factory()->for($orgB, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();
        $att = $targetB->attachments()->create([
            'org_id' => $orgB->id,
            'uploaded_by_user_id' => $uploaderB->id,
            'filename' => 'vb.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/vb.pdf',
        ]);

        $this->actingAs($userA)
            ->get("/api/attachments/{$att->id}/view")
            ->assertNotFound();
    }

    public function test_view_requires_authentication(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $uploader->id,
            'filename' => 'g.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/g.pdf',
        ]);

        // No actingAs — a non-authenticated visitor must never reach the blob.
        $this->get("/api/attachments/{$att->id}/view")
            ->assertRedirect('/login');
    }

    public function test_download_cross_org_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $uploaderB = User::factory()->for($orgB, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();
        $att = $targetB->attachments()->create([
            'org_id' => $orgB->id,
            'uploaded_by_user_id' => $uploaderB->id,
            'filename' => 'b.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/b.pdf',
        ]);

        $this->actingAs($userA)
            ->get("/api/attachments/{$att->id}/download")
            ->assertNotFound();
    }

    public function test_anyone_in_org_can_list_class_attachments(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $class->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'roster.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'disk' => 'linode',
            'path' => 'attachments/roster.pdf',
        ]);

        $this->actingAs($member)
            ->getJson('/api/attachments?'.http_build_query([
                'attachable_type' => TrainingClass::class,
                'attachable_id' => $class->id,
            ]))
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_anyone_in_org_can_upload_to_class(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->withRole('None')->create();
        $class = TrainingClass::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create('handout.pdf', 256, 'application/pdf');

        $response = $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => TrainingClass::class,
                'attachable_id' => $class->id,
                'file' => $file,
            ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $row = Attachment::firstOrFail();
        $this->assertSame(TrainingClass::class, $row->attachable_type);
        $this->assertSame($class->id, $row->attachable_id);
        $this->assertSame($org->id, $row->org_id);
        Storage::disk('linode')->assertExists($row->path);
    }

    public function test_upload_rejects_cross_org_class(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $uploaderA = User::factory()->for($orgA, 'organization')->create();
        $classB = TrainingClass::factory()->for($orgB, 'organization')->create();
        $file = UploadedFile::fake()->create('x.pdf', 32);

        $this->actingAs($uploaderA)
            ->post('/api/attachments', [
                'attachable_type' => TrainingClass::class,
                'attachable_id' => $classB->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_create_delete_broadcast(): void
    {
        Event::fake([AttachmentCreated::class, AttachmentDeleted::class]);

        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create('e.pdf', 32, 'application/pdf');

        $created = $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $target->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->json();

        $this->actingAs($uploader)->deleteJson("/api/attachments/{$created['id']}");

        Event::assertDispatched(AttachmentCreated::class);
        Event::assertDispatched(AttachmentDeleted::class);
    }

    /**
     * Storage::fake()'s built-in temporaryUrl callback only declares
     * ($path, $expiration) and silently drops the $options array, so the
     * disposition/content-type we pass never reaches the resulting URL.
     * Install our own callback (declaring all 3 params) to capture what the
     * controller actually asked for.
     */
    private function installDispositionCapture(array &$captured): void
    {
        Storage::disk('linode')->buildTemporaryUrlsUsing(
            function ($path, $expiration, array $options = []) use (&$captured) {
                $captured = $options;

                return URL::to($path);
            }
        );
    }

    public static function disallowedFileProvider(): array
    {
        return [
            'html' => ['evil.html'],
            'svg' => ['evil.svg'],
            'exe' => ['evil.exe'],
        ];
    }

    #[DataProvider('disallowedFileProvider')]
    public function test_upload_rejects_disallowed_file_type(string $filename): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $file = UploadedFile::fake()->create($filename, 10);

        $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $target->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::count());
    }

    public function test_upload_allows_each_allowlisted_extension(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        foreach (['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'] as $ext) {
            $file = UploadedFile::fake()->create("doc.{$ext}", 10);

            $this->actingAs($uploader)
                ->post('/api/attachments', [
                    'attachable_type' => User::class,
                    'attachable_id' => $target->id,
                    'file' => $file,
                ], ['Accept' => 'application/json'])
                ->assertCreated();
        }

        $this->assertSame(11, Attachment::count());
    }

    public function test_upload_stores_server_derived_mime_not_client_mime(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->create();
        $target = User::factory()->for($org, 'organization')->create();

        // Filename extension (.pdf) drives the fake's simulated *client*
        // mime; the explicit third argument simulates the real, sniffed
        // (magic-byte) mime — here deliberately different, so a stored
        // value equal to it (rather than the client one) proves the
        // controller reads getMimeType() and not getClientMimeType().
        $file = UploadedFile::fake()->create('resume.pdf', 10, 'image/png');
        $this->assertSame('application/pdf', $file->getClientMimeType());
        $this->assertSame('image/png', $file->getMimeType());

        $this->actingAs($uploader)
            ->post('/api/attachments', [
                'attachable_type' => User::class,
                'attachable_id' => $target->id,
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $row = Attachment::firstOrFail();
        $this->assertSame('image/png', $row->mime);
    }

    public function test_view_serves_inline_disposition_for_inline_safe_mime(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/inline.pdf', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'inline.pdf',
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/inline.pdf',
        ]);

        $captured = [];
        $this->installDispositionCapture($captured);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/view")
            ->assertRedirect();

        $this->assertStringStartsWith('inline;', $captured['ResponseContentDisposition']);
    }

    public function test_view_serves_attachment_disposition_for_non_inline_safe_mime(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/doc.docx', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'doc.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/doc.docx',
        ]);

        $captured = [];
        $this->installDispositionCapture($captured);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/view")
            ->assertRedirect();

        $this->assertStringStartsWith('attachment;', $captured['ResponseContentDisposition']);
    }

    public function test_view_treats_unknown_mime_as_attachment_disposition(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/legacy.bin', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'legacy.bin',
            'mime' => null,
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/legacy.bin',
        ]);

        $captured = [];
        $this->installDispositionCapture($captured);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/view")
            ->assertRedirect();

        $this->assertStringStartsWith('attachment;', $captured['ResponseContentDisposition']);
    }

    public function test_download_always_forces_attachment_disposition_even_for_inline_safe_mime(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/dl.png', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'dl.png',
            'mime' => 'image/png',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/dl.png',
        ]);

        $captured = [];
        $this->installDispositionCapture($captured);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/download")
            ->assertRedirect();

        $this->assertStringStartsWith('attachment;', $captured['ResponseContentDisposition']);
    }

    public function test_view_sanitizes_filename_with_quotes_and_crlf_in_disposition(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        Storage::disk('linode')->put('attachments/x.pdf', 'data');
        $att = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => "evil\"\r\nX-Injected: 1\r\n.pdf",
            'mime' => 'application/pdf',
            'size' => 4,
            'disk' => 'linode',
            'path' => 'attachments/x.pdf',
        ]);

        $captured = [];
        $this->installDispositionCapture($captured);

        $this->actingAs($member)
            ->get("/api/attachments/{$att->id}/view")
            ->assertRedirect();

        $disposition = $captured['ResponseContentDisposition'];
        // Exactly the pair of quotes delimiting filename="..." — no
        // attacker-supplied quote survived to break out of it.
        $this->assertSame(2, substr_count($disposition, '"'));
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
    }
}
