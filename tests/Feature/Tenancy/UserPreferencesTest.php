<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Per-user UI preferences (table column visibility/order + filter defaults).
 * Stored as an opaque JSON blob the frontend owns; the backend just persists
 * it for the authenticated user and shares it on every Inertia page.
 */
class UserPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_user_can_save_and_persist_their_preferences(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $prefs = ['tables' => ['users' => ['hidden' => ['email'], 'order' => ['name', 'role']]]];

        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['preferences' => $prefs])
            ->assertOk();

        $this->assertSame($prefs, $user->fresh()->preferences);
    }

    public function test_preferences_are_shared_on_inertia_pages(): void
    {
        $org = Organization::factory()->create();
        $prefs = ['tables' => ['users' => ['hidden' => ['email']]]];
        $user = User::factory()->for($org, 'organization')->create(['preferences' => $prefs]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.preferences', $prefs)
            );
    }

    public function test_only_updates_the_current_user(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();
        $other = User::factory()->for($org, 'organization')->create(['preferences' => ['x' => 1]]);

        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['preferences' => ['y' => 2]])
            ->assertOk();

        $this->assertSame(['y' => 2], $user->fresh()->preferences);
        $this->assertSame(['x' => 1], $other->fresh()->preferences);
    }

    public function test_rejects_non_array_preferences(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->create();

        $this->actingAs($user)
            ->patchJson('/api/me/preferences', ['preferences' => 'not-an-array'])
            ->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->patchJson('/api/me/preferences', ['preferences' => []])
            ->assertUnauthorized();
    }
}
