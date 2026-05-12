<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia smoke test for /notifications. The page itself contracts
 * only on rendering the component; the data flow + authz is covered
 * by NotificationsApiTest.
 */
class NotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_user_can_view_inbox(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();

        $this->actingAs($user)
            ->get(route('notifications.page'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('notifications/Index'));
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('notifications.page'))->assertRedirect(route('login'));
    }
}
