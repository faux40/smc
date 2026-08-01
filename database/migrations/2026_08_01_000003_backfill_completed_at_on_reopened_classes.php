<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only: no schema change.
 *
 * `completion_date` used to double as the flag for "this class has been closed
 * at least once" — re-open deliberately keeps the date while clearing
 * `completed_at`, so the date was the only survivor. That reading blocks
 * recording a completion date *before* close-out, which is what a multi-day
 * class needs, so the flag moves to `completed_at` (re-open now leaves it
 * alone; `status` already carries the open/closed state on its own).
 *
 * Classes re-opened before that change have the old shape — a completion date
 * with no `completed_at` — and would otherwise read as never-completed, which
 * would refuse their re-close and hide their re-issue action. Stamp them from
 * the date they were closed on.
 *
 * Safe to run once, at deploy, and only then: it keys off a completion date on
 * a class that has no `completed_at`, which from here on can only mean the
 * old shape. Nobody can have pre-entered a date yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('classes')
            ->whereNull('completed_at')
            ->whereNotNull('completion_date')
            ->update(['completed_at' => DB::raw('completion_date')]);
    }

    public function down(): void
    {
        // Irreversible by nature: the pre-migration state can't be told apart
        // from a class legitimately closed on its completion date.
    }
};
