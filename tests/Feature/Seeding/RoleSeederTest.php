<?php

namespace Tests\Feature\Seeding;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public const EXPECTED_ROLES = [
        'Owner',
        'SuperAdmin',
        'Admin',
        'Manager',
        'SelfEdit',
        'SelfView',
        'None',
    ];

    public function test_seeder_creates_all_seven_roles(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (self::EXPECTED_ROLES as $name) {
            $this->assertNotNull(
                Role::where('name', $name)->first(),
                "Role '{$name}' was not seeded.",
            );
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(
            count(self::EXPECTED_ROLES),
            Role::query()->whereIn('name', self::EXPECTED_ROLES)->count(),
        );
    }
}
