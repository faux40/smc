<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 15.6 — org-local scheduling for the weekly manager digest.
     *
     * `timezone` — IANA identifier (default 'UTC'); the digest command
     * runs hourly and fires for an org when it's Monday 08:00 *there*.
     * Editable from the org settings page.
     *
     * `manager_digest_sent_at` — last time the digest went out for the
     * org. Guards against a double-send within the matching hour and
     * on manual re-runs: the command only sends when this is null or
     * older than the start of the current week in the org's timezone.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->after('name');
            $table->timestamp('manager_digest_sent_at')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'manager_digest_sent_at']);
        });
    }
};
