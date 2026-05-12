<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia smoke test for /assignments (Phase 13.2 admin page). The page
 * renders for any authenticated org member; AssignmentsApiTest covers
 * the API role gating that drives the "+ New" / Edit / Delete affordances.
 */
class AssignmentsPageTest extends TestCase
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
        $member = User::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->get(route('assignments.page'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('assignments/Index'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('assignments.page'))->assertRedirect(route('login'));
    }
}
