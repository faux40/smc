<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Block duplicate requirement names within an org (case-insensitive).
 *
 * Indexes `lower(name)` so "Forklift" and "forklift" collide; partial on
 * `deleted_at IS NULL` so a soft-deleted requirement frees its name for
 * reuse. The controller adds a matching validation rule for a clean 422;
 * this index is the backstop. Expression + partial index syntax works on
 * both Postgres (prod) and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX requirements_org_name_unique ON requirements (org_id, lower(name)) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS requirements_org_name_unique');
    }
};
