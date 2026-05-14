<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 15.3 — backs the daily due-state watchdog. One row per
     * assignment recording the status the watchdog last observed, so it
     * can fire `AssignmentDueSoon` / `AssignmentOverdue` only on the edge
     * transition into those states rather than every day the assignment
     * remains there.
     *
     * No soft-deletes: the row is bookkeeping, cascade-deleted with its
     * assignment. No `last_notified_*_at` columns — the transition is
     * derived purely from `last_seen_status` vs the freshly computed one.
     */
    public function up(): void
    {
        Schema::create('assignment_notification_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('assignment_id')->unique()->constrained('assignments')->cascadeOnDelete();
            // 'overdue' | 'due_soon' | 'current' | 'never_started' | 'inactive'
            $table->string('last_seen_status');
            $table->timestamps();

            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_notification_states');
    }
};
