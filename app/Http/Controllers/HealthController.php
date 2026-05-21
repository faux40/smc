<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 16.5 — detailed liveness endpoint.
 *
 * Laravel's `/up` only confirms the app boots. This reports whether the
 * background daemons are actually alive: the scheduler and the queue worker
 * each stamp a cache heartbeat every minute (see routes/console.php +
 * App\Jobs\QueueHeartbeat); a stale stamp means that daemon is down. Reverb is
 * a websocket server with no HTTP health route — monitor its port externally.
 *
 * Public + minimal: booleans + ages only, no infra detail. 200 when healthy,
 * 503 when degraded so uptime monitors and load balancers can react.
 */
class HealthController extends Controller
{
    public const SCHEDULER_KEY = 'health:scheduler:last_run';

    public const QUEUE_KEY = 'health:queue:last_run';

    /** A heartbeat older than this (seconds) means the daemon is likely down. */
    private const STALE_AFTER = 300;

    public function detailed(): JsonResponse
    {
        $scheduler = $this->check(self::SCHEDULER_KEY);
        $queue = $this->check(self::QUEUE_KEY);

        $ok = $scheduler['ok'] && $queue['ok'];

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => [
                'scheduler' => $scheduler,
                'queue' => $queue,
            ],
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, last_run_age_seconds: int|null}
     */
    private function check(string $key): array
    {
        $last = Cache::get($key);

        if ($last === null) {
            return ['ok' => false, 'last_run_age_seconds' => null];
        }

        $age = now()->timestamp - (int) $last;

        return ['ok' => $age <= self::STALE_AFTER, 'last_run_age_seconds' => $age];
    }
}
