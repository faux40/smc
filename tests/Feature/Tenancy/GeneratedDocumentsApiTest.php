<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\GenerateDocument;
use App\Models\DocTemplate;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeneratedDocumentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('linode');
    }

    public function test_manager_can_request_generation_and_the_job_is_queued(): void
    {
        Bus::fake([GenerateDocument::class]);
        $org = Organization::factory()->create(['name' => 'Rio Dell']);
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create(['name' => 'HazCom Policy']);

        $response = $this->actingAs($manager)
            ->postJson('/api/generated-documents', [
                'doc_template_id' => $template->id,
                'location' => 'North Yard',
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('queued', $response['status']);
        $this->assertSame('North Yard', $response['location']);
        $this->assertStringContainsString('rio_dell', $response['filename']);
        $this->assertStringContainsString('hazcom_policy', $response['filename']);

        Bus::assertDispatched(GenerateDocument::class, fn ($job) => $job->generatedDocumentId === $response['id']);
    }

    public function test_below_manager_cannot_generate(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $template = DocTemplate::factory()->system()->create();

        $this->actingAs($member)
            ->postJson('/api/generated-documents', ['doc_template_id' => $template->id])
            ->assertForbidden();
    }

    public function test_foreign_org_template_is_rejected(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $templateB = DocTemplate::factory()->for($orgB, 'organization')->create();

        $this->actingAs($managerA)
            ->postJson('/api/generated-documents', ['doc_template_id' => $templateB->id])
            ->assertStatus(422);
    }

    public function test_index_is_org_scoped_and_paginated(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        GeneratedDocument::factory()->for($orgA, 'organization')->for($template, 'template')->count(2)->create();
        GeneratedDocument::factory()->for($orgB, 'organization')->for($template, 'template')->create();

        $response = $this->actingAs($managerA)
            ->getJson('/api/generated-documents')
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['data']);
        $this->assertSame(2, $response['meta']['total']);
    }

    public function test_download_redirects_when_done_and_409s_when_not(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();

        Storage::disk('linode')->put('generated-documents/x.pdf', '%PDF-fake');
        $done = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'done',
            'pdf_path' => 'generated-documents/x.pdf',
        ]);
        $queued = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create();

        $this->actingAs($manager)
            ->get("/api/generated-documents/{$done->id}/download?format=pdf")
            ->assertRedirect();

        $this->actingAs($manager)
            ->get("/api/generated-documents/{$queued->id}/download")
            ->assertStatus(409);
    }

    public function test_cross_org_download_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $docB = GeneratedDocument::factory()->for($orgB, 'organization')->for($template, 'template')->create();

        $this->actingAs($managerA)
            ->get("/api/generated-documents/{$docB->id}/download")
            ->assertNotFound();
    }

    /**
     * A failed row's `updated_at` IS the moment it failed, and without it on
     * the wire the UI cannot date the error — which is how a 19-day-old
     * fossil read as a live outage in prod on 2026-08-04.
     */
    public function test_index_exposes_updated_at_so_a_failure_can_be_dated(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
            'error' => 'PDF conversion failed: sh: 1: exec: soffice: not found',
        ]);

        // Bypass the model so the timestamp is not refreshed on write.
        DB::table('generated_documents')
            ->where('id', $doc->id)
            ->update(['updated_at' => '2026-07-13 20:47:50']);

        $row = $this->actingAs($manager)
            ->getJson('/api/generated-documents')
            ->assertOk()
            ->json('data.0');

        $this->assertNotNull($row['updated_at']);
        $this->assertSame(
            '2026-07-13T20:47:50.000000Z',
            $row['updated_at'],
        );
    }

    public function test_manager_can_retry_a_failed_row(): void
    {
        Bus::fake([GenerateDocument::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
            'error' => 'PDF conversion failed: sh: 1: exec: soffice: not found',
            'location' => 'North Yard',
        ]);

        $response = $this->actingAs($manager)
            ->postJson("/api/generated-documents/{$doc->id}/retry")
            ->assertOk()
            ->json();

        $this->assertSame('queued', $response['status']);
        $this->assertNull($response['error']);
        // The variation the row already recorded survives the retry — that is
        // the whole point of retrying rather than deleting and re-picking.
        $this->assertSame('North Yard', $response['location']);

        $this->assertDatabaseHas('generated_documents', [
            'id' => $doc->id,
            'status' => 'queued',
            'error' => null,
            'location' => 'North Yard',
        ]);

        Bus::assertDispatched(GenerateDocument::class, fn ($job) => $job->generatedDocumentId === $doc->id);
    }

    public function test_retry_rejects_a_row_that_did_not_fail(): void
    {
        Bus::fake([GenerateDocument::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $done = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'done',
            'pdf_path' => 'generated-documents/z.pdf',
        ]);

        $this->actingAs($manager)
            ->postJson("/api/generated-documents/{$done->id}/retry")
            ->assertStatus(409);

        Bus::assertNotDispatched(GenerateDocument::class);
        $this->assertDatabaseHas('generated_documents', ['id' => $done->id, 'status' => 'done']);
    }

    /**
     * The FK is `nullOnDelete`, so hard-deleting a template orphans the row
     * rather than removing it. `GenerateDocument` early-returns on a null
     * template *before* it sets `processing`, so queueing one here would park
     * the row at `queued` forever — a worse lie than the failure it replaced.
     */
    public function test_retry_rejects_a_row_whose_template_was_hard_deleted(): void
    {
        Bus::fake([GenerateDocument::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
            'error' => 'boom',
        ]);

        $template->forceDelete();

        $this->actingAs($manager)
            ->postJson("/api/generated-documents/{$doc->id}/retry")
            ->assertStatus(409);

        Bus::assertNotDispatched(GenerateDocument::class);
        $this->assertDatabaseHas('generated_documents', ['id' => $doc->id, 'status' => 'failed']);
    }

    /**
     * A *soft*-deleted template is the replaced-version case, which the
     * relation loads `withTrashed()` on purpose — retrying reproduces the
     * document from the template it was actually generated with.
     */
    public function test_retry_allows_a_row_whose_template_was_superseded(): void
    {
        Bus::fake([GenerateDocument::class]);
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
            'error' => 'boom',
        ]);

        $template->delete();

        $this->actingAs($manager)
            ->postJson("/api/generated-documents/{$doc->id}/retry")
            ->assertOk();

        Bus::assertDispatched(GenerateDocument::class);
    }

    public function test_cross_org_retry_is_404(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $managerA = User::factory()->for($orgA, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        $docB = GeneratedDocument::factory()->for($orgB, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
        ]);

        $this->actingAs($managerA)
            ->postJson("/api/generated-documents/{$docB->id}/retry")
            ->assertNotFound();
    }

    public function test_below_manager_cannot_retry(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $template = DocTemplate::factory()->system()->create();
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'failed',
        ]);

        $this->actingAs($member)
            ->postJson("/api/generated-documents/{$doc->id}/retry")
            ->assertForbidden();
    }

    public function test_delete_removes_row_and_files(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $template = DocTemplate::factory()->system()->create();
        Storage::disk('linode')->put('generated-documents/y.docx', 'fake');
        Storage::disk('linode')->put('generated-documents/y.pdf', '%PDF-fake');
        $doc = GeneratedDocument::factory()->for($org, 'organization')->for($template, 'template')->create([
            'status' => 'done',
            'merged_path' => 'generated-documents/y.docx',
            'pdf_path' => 'generated-documents/y.pdf',
        ]);

        $this->actingAs($manager)
            ->deleteJson("/api/generated-documents/{$doc->id}")
            ->assertOk();

        $this->assertDatabaseMissing('generated_documents', ['id' => $doc->id]);
        Storage::disk('linode')->assertMissing('generated-documents/y.docx');
        Storage::disk('linode')->assertMissing('generated-documents/y.pdf');
    }
}
