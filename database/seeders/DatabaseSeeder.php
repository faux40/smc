<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Bootstrap-essential seeders only. Demo data lives in its own
     * dev-only seeder when we need one.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            TemplateOrgSeeder::class,
        ]);
    }
}
