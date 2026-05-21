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
 * Note on the mechanism: route-model binding runs in SubstituteBindings,
 * which is *before* SetCurrentOrgId binds `currentOrgId` — so the org global
 * scope no-ops during binding and a cross-org id actually resolves. The
 * per-resource policy (which checks same-org) is what denies it → 403. These
 * tests pin that every bound endpoint enforces the policy, since the global
 * scope can't be relied on at the binding layer.
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

        $this->actingAs($adminA)->get("/users/{$targetB->id}")->assertForbidden();
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
            ->assertForbidden();
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
            ->assertForbidden();
    }
}
