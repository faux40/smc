<?php

namespace Tests\Feature\Health;

use App\Jobs\QueueHeartbeat;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Phase 16.5 — liveness checks.
 *
 * The scheduler and queue worker each stamp a cache heartbeat every minute;
 * /health/detailed reports them stale-or-fresh so an uptime monitor catches a
 * dead daemon (which `/up` — framework-boots-only — cannot). Public + minimal
 * (booleans + ages, no infra detail).
 */
class HealthCheckTest extends TestCase
{
    private const SCHEDULER_KEY = 'health:scheduler:last_run';

    private const QUEUE_KEY = 'health:queue:last_run';

    public function test_reports_ok_when_both_heartbeats_are_fresh(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->timestamp);
        Cache::put(self::QUEUE_KEY, now()->timestamp);

        $this->getJson('/health/detailed')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.scheduler.ok', true)
            ->assertJsonPath('checks.queue.ok', true);
    }

    public function test_reports_503_degraded_when_scheduler_heartbeat_is_stale(): void
    {
        Cache::put(self::SCHEDULER_KEY, now()->subMinutes(20)->timestamp);
        Cache::put(self::QUEUE_KEY, now()->timestamp);

        $this->getJson('/health/detailed')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.queue.ok', true);
    }

    public function test_reports_503_when_a_heartbeat_never_ran(): void
    {
        Cache::forget(self::SCHEDULER_KEY);
        Cache::forget(self::QUEUE_KEY);

        $this->getJson('/health/detailed')
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler.ok', false);
    }

    public function test_endpoint_is_public(): void
    {
        // Uptime monitors hit it unauthenticated — must not redirect to login.
        Cache::put(self::SCHEDULER_KEY, now()->timestamp);
        Cache::put(self::QUEUE_KEY, now()->timestamp);

        $this->get('/health/detailed')->assertOk();
    }

    public function test_queue_heartbeat_job_stamps_the_cache(): void
    {
        Cache::forget(self::QUEUE_KEY);

        (new QueueHeartbeat)->handle();

        $this->assertNotNull(Cache::get(self::QUEUE_KEY));
    }
}
