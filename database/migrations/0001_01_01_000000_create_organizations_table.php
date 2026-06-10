<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable on create — the new-user-creates-new-org transaction
            // sets it immediately after, but the User row needs to exist first.
            // Constrained FK added by the users redesign migration so the FK
            // arrow points at a real table.
            $table->uuid('owner_user_id')->nullable();
            $table->string('name');
            // Org-local scheduling for the weekly manager digest (Phase 15.6).
            // timezone: IANA id, default UTC — digest fires when it's Mon
            // 08:00 there. manager_digest_sent_at: last send; guards a
            // double-send within the matching hour and on manual re-runs.
            $table->string('timezone')->default('UTC');
            $table->timestamp('manager_digest_sent_at')->nullable();
            // Per-org training status thresholds (app-defined JSON shape).
            $table->json('training_thresholds')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
