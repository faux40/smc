<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests that the User model integrates with spatie roles. Full
 * role-based authorization is exercised by feature tests in 3.4 and
 * subsequent phases — here we just verify the trait is wired and the
 * UUID column types align so role assignment + lookup work.
 */
class UserHasRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_user_can_be_assigned_role(): void
    {
        $user = User::factory()->create();

        $user->assignRole('Admin');

        $this->assertTrue($user->fresh()->hasRole('Admin'));
    }

    public function test_user_factory_with_role_state(): void
    {
        $user = User::factory()->withRole('Manager')->create();

        $this->assertTrue($user->hasRole('Manager'));
    }

    public function test_has_any_role_helper(): void
    {
        $user = User::factory()->withRole('Manager')->create();

        $this->assertTrue($user->hasAnyRole(['Owner', 'Manager']));
        $this->assertFalse($user->hasAnyRole(['Owner', 'SuperAdmin']));
    }
}
