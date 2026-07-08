<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F10 — org-configurable re-fire interval for the `overdue` state. When
     * set (a positive day count), the daily watchdog re-sends
     * AssignmentOverdue every N days that an assignment stays overdue, instead
     * of only once on the edge into overdue. Null / 0 = disabled (the prior
     * behaviour), so nothing changes until a manager opts in.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('overdue_reminder_interval_days')
                ->nullable()
                ->after('training_thresholds');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('overdue_reminder_interval_days');
        });
    }
};
