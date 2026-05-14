<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
