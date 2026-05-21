<?php

use App\Http\Controllers\HealthController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 15.3 — daily due-state watchdog. Single global tick at 06:00
// server time; org-timezone-aware scheduling is the 15.6 digest concern.
Schedule::command('assignments:scan-due-states')->dailyAt('06:00');

// Phase 15.6 — weekly manager compliance digest. Runs hourly; the
// command itself decides per-org whether it's currently Monday 08:00
// in that org's timezone.
Schedule::command('digests:send-manager-compliance')->hourly();

// Phase 16.5 — liveness heartbeats consumed by /health/detailed.
// Scheduler heartbeat runs in the scheduler process itself; the queue
// heartbeat is dispatched here but only stamps once a worker processes it —
// so a stale queue stamp isolates a dead worker from a dead scheduler.
Schedule::call(fn () => Cache::put(HealthController::SCHEDULER_KEY, now()->timestamp))
    ->everyMinute()
    ->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyMinute()->name('queue-heartbeat');
