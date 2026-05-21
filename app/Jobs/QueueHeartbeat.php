<?php

namespace App\Jobs;

use App\Http\Controllers\HealthController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 16.5 — queue-worker liveness heartbeat. Dispatched every minute by the
 * scheduler; stamps a cache timestamp when a worker actually processes it. If
 * the worker is down the stamp goes stale and /health/detailed reports the
 * queue check as failing.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(HealthController::QUEUE_KEY, now()->timestamp);
    }
}
