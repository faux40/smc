<?php

namespace Tests\Feature\Resilience;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 16.3 — broadcast-layer failure isolation.
 *
 * Controllers are "Reverb-first" and broadcast on every mutation, but every
 * broadcast event implements ShouldBroadcast (queued), not ShouldBroadcastNow.
 * So the actual Reverb publish runs in a queue worker, never in the request:
 * a Reverb outage or a throwing broadcaster fails the queued job (→
 * failed_jobs) while the mutation response is already a clean 2xx.
 *
 * This test pins that architectural guarantee: a mutation returns 2xx and the
 * broadcast is *deferred* to the queue rather than performed inline.
 */
class BroadcastResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_mutation_returns_2xx_and_defers_the_broadcast_to_the_queue(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org, 'organization')->create();
        $admin->assignRole('Admin');

        Queue::fake();

        $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'forklift', 'color' => '#ff0000'])
            ->assertCreated();

        // The broadcast is queued, not run inline — so a broadcast-layer
        // failure is isolated to the worker and can't 500 the mutation.
        Queue::assertPushed(BroadcastEvent::class);
    }
}
