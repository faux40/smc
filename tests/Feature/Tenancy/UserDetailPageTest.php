<?php

namespace Tests\Feature\Tenancy;

use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Coverage for /users/{user} (Inertia page) and
 * /api/users/{user}/compliance (JSON endpoint).
 *
 * Authz matrix (per UserPolicy::viewDetail):
 *   - Owner / SA / Admin / Manager → any user same-org
 *   - Any role → own user
 *   - Cross-org → 403
 *   - Else → 403
 *
 * The compliance math itself is covered by UserComplianceTest in
 * isolation; here we only verify the endpoint returns the expected
 * payload shape under the policy.
 */
class UserDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_view_any_org_user_detail_page(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->actingAs($admin)
            ->get(route('users.show', $target))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('users/Show')
                ->where('subject.id', $target->id),
            );
    }

    public function test_manager_can_view_any_org_user_detail_page(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->actingAs($manager)
            ->get(route('users.show', $target))
            ->assertOk();
    }

    public function test_self_view_own_detail_page_for_any_role(): void
    {
        $org = Organization::factory()->create();

        foreach (['SelfView', 'SelfEdit', 'None'] as $role) {
            $u = User::factory()->for($org, 'organization')->withRole($role)->create();
            $this->actingAs($u)
                ->get(route('users.show', $u))
                ->assertOk();
        }
    }

    public function test_non_admin_cannot_view_someone_elses_detail_page(): void
    {
        $org = Organization::factory()->create();
        $a = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $b = User::factory()->for($org, 'organization')->create();

        $this->actingAs($a)
            ->get(route('users.show', $b))
            ->assertForbidden();
    }

    public function test_cross_org_view_blocked(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $adminA = User::factory()->for($orgA, 'organization')->withRole('Admin')->create();
        $userB = User::factory()->for($orgB, 'organization')->create();

        $this->actingAs($adminA)
            ->get(route('users.show', $userB))
            ->assertNotFound();
    }

    public function test_compliance_endpoint_returns_expected_shape(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->create();
        // One assignment so the response isn't trivially empty.
        $req = Requirement::factory()->for($org, 'organization')->create();
        Assignment::factory()->for($org, 'organization')->for($target, 'user')->for($req, 'requirement')->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/users/{$target->id}/compliance")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('groups', $response);
        $this->assertArrayHasKey('completions', $response);
        foreach (['overdue', 'due_soon', 'current', 'never_started', 'inactive'] as $bucket) {
            $this->assertArrayHasKey($bucket, $response['groups']);
        }
    }

    public function test_compliance_endpoint_is_authz_gated(): void
    {
        $org = Organization::factory()->create();
        $a = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $b = User::factory()->for($org, 'organization')->create();

        $this->actingAs($a)
            ->getJson("/api/users/{$b->id}/compliance")
            ->assertForbidden();
    }

    public function test_guest_redirected_from_detail_page(): void
    {
        $org = Organization::factory()->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->get(route('users.show', $target))
            ->assertRedirect(route('login'));
    }
}
