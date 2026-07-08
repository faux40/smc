<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F10 — records when the watchdog last sent ANY due/overdue notification
     * for a training assignment. The overdue re-fire (org
     * `overdue_reminder_interval_days`) keys off it: re-send only when the
     * last send is older than the interval. Nullable so pre-existing rows read
     * as "never notified"; the watchdog seeds the clock on first sight rather
     * than firing a storm.
     */
    public function up(): void
    {
        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->timestamp('last_notified_at')->nullable()->after('last_seen_status');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->dropColumn('last_notified_at');
        });
    }
};
