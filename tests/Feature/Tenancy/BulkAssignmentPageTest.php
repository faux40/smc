<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia smoke test for /workflows/bulk-assignment. The page itself
 * renders for any authenticated org member but page-level UI hides
 * controls behind a Manager-or-higher check; the API endpoints enforce
 * the policy. BulkAssignmentsApiTest covers the authz side.
 */
class BulkAssignmentPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_user_can_view_page(): void
    {
        $org = Organization::factory()->create();
        $manager = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        $this->actingAs($manager)
            ->get(route('workflows.bulk-assignment'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('workflows/BulkAssignment'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('workflows.bulk-assignment'))->assertRedirect(route('login'));
    }
}
