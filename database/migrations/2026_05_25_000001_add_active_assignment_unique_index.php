<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Block duplicate *active* assignments for the same (user, requirement).
 *
 * "Active" = not ended (`end_date IS NULL`) and not soft-deleted. Ending an
 * assignment (setting end_date) or soft-deleting it frees the pair to be
 * reassigned later, so the index is partial. The bulk flow already dedups in
 * app logic; this guards the single-create path (incl. the per-user
 * quick-add) at the DB. Partial-index syntax is identical on Postgres (prod)
 * and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX assignments_active_unique ON assignments (user_id, requirement_id) WHERE end_date IS NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS assignments_active_unique');
    }
};
