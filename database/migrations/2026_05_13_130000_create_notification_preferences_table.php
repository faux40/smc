<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 15.5 — per-user, per-type, per-channel notification toggles.
     *
     * Personal preference, not an org resource: keyed on `user_id` only,
     * no `org_id`. A row exists only to record state — a *missing*
     * (user, type, channel) row reads as enabled (the default is on).
     * `ChannelsWithGatedMail::via()` consults this table beneath the
     * deployment-level `notifications.mail_enabled` flag from 15.4.
     *
     * `channel` is one of two logical values — `inapp` (the database +
     * broadcast pair, moved as a unit) or `mail` — not the raw Laravel
     * channel names.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // 'assignment_created' | 'completion_recorded'
            //   | 'assignment_due_soon' | 'assignment_overdue'
            $table->string('type');
            // 'inapp' | 'mail'
            $table->string('channel');
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
