<?php

namespace Tests\Feature\Personas;

use App\Models\Organization;
use App\Models\StdFrequency;
use App\Models\Training;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persona acceptance suites (Phase P) — externally-framed, workflow-level
 * tests, one suite per persona, asserting what that person *sees and can
 * do*, not implementation internals.
 *
 * Run them by group:
 *   php artisan test --group=persona            (all personas)
 *   php artisan test --group=persona-user       (typical user)
 *   php artisan test --group=persona-manager    (training manager)
 *   php artisan test --group=persona-owner      (the boss / owner)
 *   php artisan test --group=persona-outsider   (wrong-org actor)
 *
 * The frontend halves live in resources/js/personas/*.persona.spec.ts
 * (npm run test:personas runs both sides).
 */
abstract class PersonaTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->org = Organization::factory()->create();
    }

    protected function actor(string $role): User
    {
        return User::factory()
            ->for($this->org, 'organization')
            ->withRole($role)
            ->create();
    }

    protected function annualFrequency(?Organization $org = null): StdFrequency
    {
        return StdFrequency::create([
            'org_id' => ($org ?? $this->org)->id,
            'name' => 'Annual',
            'repeat_days' => 365,
        ]);
    }

    protected function repeatingTraining(string $name, StdFrequency $freq, ?Organization $org = null): Training
    {
        return Training::factory()
            ->for($org ?? $this->org, 'organization')
            ->create([
                'name' => $name,
                'initial_only' => false,
                'repeating' => true,
                'as_needed' => false,
                'std_freq_id' => $freq->id,
            ]);
    }
}
