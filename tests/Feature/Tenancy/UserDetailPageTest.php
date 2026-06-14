<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
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

    public function test_detail_page_carries_profile_fields_and_supervisor_name(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $supervisor = User::factory()->for($org, 'organization')->create(['f_name' => 'Sue', 'l_name' => 'Boss']);
        $target = User::factory()->for($org, 'organization')->create([
            'department' => 'Field Ops',
            'job_title' => 'Operator',
            'supervisor_id' => $supervisor->id,
        ]);

        $this->actingAs($admin)
            ->get(route('users.show', $target))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subject.department', 'Field Ops')
                ->where('subject.job_title', 'Operator')
                ->where('subject.supervisor_id', $supervisor->id)
                ->where('subject.supervisor_name', 'Sue Boss')
            );
    }

    public function test_detail_page_exposes_can_edit_per_policy(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->withRole('None')->create();

        // Admin viewing another user → editable.
        $this->actingAs($admin)
            ->get(route('users.show', $target))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subject.can_edit', true)
            );

        // A SelfEdit user can view their own page but not edit it from here
        // (admin update gate excludes self-service roles).
        $selfEdit = User::factory()->for($org, 'organization')->withRole('SelfEdit')->create();
        $this->actingAs($selfEdit)
            ->get(route('users.show', $selfEdit))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('subject.can_edit', false)
            );
    }

    public function test_update_returns_to_the_detail_page_when_launched_from_it(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->withRole('Admin')->create();
        $target = User::factory()->for($org, 'organization')->withRole('None')->create();

        $this->actingAs($admin)
            ->from(route('users.show', $target))
            ->patch(route('users.update', $target), [
                'f_name' => 'Renamed',
                'l_name' => $target->l_name,
                'role' => 'None',
                'status' => 'active',
            ])
            ->assertRedirect(route('users.show', $target));

        $this->assertSame('Renamed', $target->refresh()->f_name);
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

    // The compliance payload + its authz now live on the TA-engine
    // endpoint — covered by UserTrainingComplianceTest (J5).

    public function test_guest_redirected_from_detail_page(): void
    {
        $org = Organization::factory()->create();
        $target = User::factory()->for($org, 'organization')->create();

        $this->get(route('users.show', $target))
            ->assertRedirect(route('login'));
    }
}
