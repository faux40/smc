<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('requirement_id')->constrained('requirements')->cascadeOnDelete();
            // No per-assignment timing: an assignment spans the whole
            // requirement, whose RqmtElements each carry their own timing.
            // Compliance is computed purely from element timing
            // (UserComplianceCalculator), so nothing lives here.
            // Copy of the source module's name/description at assign-time so
            // assignment display is stable even if the source is renamed.
            $table->string('name');
            $table->text('description')->nullable();
            // start_date required; end_date nullable = active. Setting end_date
            // is the "deactivate" signal — no hard-delete of used assignments.
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['user_id', 'requirement_id']);
        });

        // Block duplicate *active* assignments for the same (user, requirement).
        // "Active" = not ended (end_date IS NULL) and not soft-deleted; ending
        // or soft-deleting frees the pair to be reassigned. Partial-index
        // syntax is identical on Postgres (prod) and SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX assignments_active_unique ON assignments (user_id, requirement_id) WHERE end_date IS NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS assignments_active_unique');
        Schema::dropIfExists('assignments');
    }
};
