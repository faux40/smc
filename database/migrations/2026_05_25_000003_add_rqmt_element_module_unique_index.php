<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Block binding the same module to a requirement twice.
 *
 * A requirement shouldn't list the same Training (or future module type)
 * more than once. Partial unique index on (requirement_id, module_type,
 * module_id) for non-deleted rows, so soft-deleting a binding frees it to be
 * re-added. RqmtElementRequest adds a matching validation rule for a clean
 * 422; this index is the backstop. Works on Postgres (prod) + SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX rqmt_elements_module_unique ON rqmt_elements (requirement_id, module_type, module_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rqmt_elements_module_unique');
    }
};
