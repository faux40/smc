<?php

namespace Tests\Feature\Resilience;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 16.4 — cross-org IDOR lock-in.
 *
 * BelongsToOrganization::resolveRouteBindingQuery constrains route-model
 * binding to the authenticated user's org, so a cross-org id never resolves —
 * it 404s before any controller/policy logic. (The per-resource policy is a
 * second line of defense behind this.) These pin the 404 against regressions.
 */
class CrossOrgIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_cannot_view_another_orgs_user_detail(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->create();
        $adminA->assignRole('Admin');
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)->get("/users/{$targetB->id}")->assertNotFound();
    }

    public function test_cannot_fetch_another_orgs_user_compliance(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->create();
        $adminA->assignRole('Admin');
        $targetB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->getJson("/api/users/{$targetB->id}/compliance")
            ->assertNotFound();
    }

    public function test_cannot_download_another_orgs_attachment(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->for($orgA, 'organization')->create();
        $targetB = User::factory()->for($orgB, 'organization')->create();
        $attachmentB = $targetB->attachments()->create([
            'org_id' => $orgB->id,
            'uploaded_by_user_id' => $targetB->id,
            'filename' => 'secret.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'disk' => 'linode',
            'path' => 'attachments/secret.pdf',
        ]);

        $this->actingAs($userA)
            ->getJson("/api/attachments/{$attachmentB->id}/download")
            ->assertNotFound();
    }
}
