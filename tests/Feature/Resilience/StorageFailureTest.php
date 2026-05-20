<?php

namespace Tests\Feature\Resilience;

use App\Models\Attachment;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Phase 16.3 — storage-failure injection.
 *
 * The `linode` disk is configured `throw => false`, so a backend outage makes
 * putFileAs return false (not raise). The upload path must treat that as a
 * clean failure — no orphan DB row pointing at a blob that was never written —
 * and the download path must not 500 when the object store is unreachable.
 */
class StorageFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_upload_failure_returns_503_and_persists_no_row(): void
    {
        $org = Organization::factory()->create();
        $uploader = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();

        // Object store is down: putFileAs returns false (disk throw=false).
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('linode')->andReturn($disk);

        $response = $this->actingAs($uploader)->post('/api/attachments', [
            'attachable_type' => User::class,
            'attachable_id' => $target->id,
            'file' => UploadedFile::fake()->create('cert.pdf', 256, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(503);
        $this->assertSame(0, Attachment::count(), 'A failed upload must not leave an orphan row.');
    }

    public function test_download_storage_failure_returns_clean_error_not_500(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('None')->create();
        $target = User::factory()->for($org, 'organization')->create();
        $attachment = $target->attachments()->create([
            'org_id' => $org->id,
            'uploaded_by_user_id' => $member->id,
            'filename' => 'cert.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'disk' => 'linode',
            'path' => 'attachments/abc.pdf',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('temporaryUrl')->andThrow(new \RuntimeException('S3 unreachable'));
        Storage::shouldReceive('disk')->with('linode')->andReturn($disk);

        $this->actingAs($member)
            ->get("/api/attachments/{$attachment->id}/download", ['Accept' => 'application/json'])
            ->assertStatus(503);
    }
}
