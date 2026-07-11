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
