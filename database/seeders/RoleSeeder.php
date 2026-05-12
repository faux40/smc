<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the seven canonical SMC roles (global, not per-org). Idempotent —
 * `firstOrCreate` is safe to re-run. Permission grants per-role are
 * tuned in later phases as features land; for now roles exist by name
 * only.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = [
        'Owner',
        'SuperAdmin',
        'Admin',
        'Manager',
        'SelfEdit',
        'SelfView',
        'None',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
