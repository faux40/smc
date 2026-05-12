<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia smoke test for the Tags library admin page at /tags. Role gating
 * is page-level (button visibility): the page renders for any org member,
 * but the writes are gated by the existing TagPolicy → TagsApiTest covers
 * the 403s on the API side.
 */
class TagsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_user_can_view_tags_page(): void
    {
        $org = Organization::factory()->create();
        $member = User::factory()->for($org, 'organization')->create();

        $this->actingAs($member)
            ->get(route('tags.page'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('tags/Index'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('tags.page'))->assertRedirect(route('login'));
    }
}
