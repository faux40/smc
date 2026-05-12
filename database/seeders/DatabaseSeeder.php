<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Bootstrap-essential seeders + dev-only data in local env.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            TemplateOrgSeeder::class,
        ]);

        // Dev-only convenience: a known-good owner account for quick login,
        // then a populated org (users / trainings / requirements / assignments)
        // layered on top via DevDataSeeder. Production envs never see this.
        if (app()->environment('local')) {
            $this->call(DevSeeder::class);
            $this->call(DevDataSeeder::class);
        }
    }
}
