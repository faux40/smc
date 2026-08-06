<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `taggables` its own `org_id`.
 *
 * Tenancy on a read of `$model->tags` has been resting on Tag's `organization`
 * global scope, but that scope is a deliberate no-op whenever `currentOrgId`
 * is unbound (queue jobs, console commands, seeders — see
 * BelongsToOrganization). The pivot therefore cannot borrow the org from the
 * join; it carries it. HasTags stamps it on every attach so no call site has
 * to remember, and NOT NULL means a path that bypasses the stamp fails at the
 * database instead of writing an unattributable row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taggables', function (Blueprint $table) {
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
        });

        // The tag is the authoritative source: TagsController::resolveAndAuthorize
        // has always required tag and morphable to share the acting user's org,
        // so tag_id -> tags.org_id reproduces what the row was created under.
        // Correlated subquery rather than Postgres' UPDATE ... FROM: the test
        // suite runs on SQLite.
        DB::statement('UPDATE taggables SET org_id = (SELECT org_id FROM tags WHERE tags.id = taggables.tag_id)');

        // Rows whose tag no longer exists cannot be attributed. The tag_id FK
        // cascades, so this should already be empty; deleting is safe either
        // way, because such a row can never resolve to a visible tag.
        DB::table('taggables')->whereNull('org_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            // Explicit DDL on the driver that carries real data, so tightening
            // nullability cannot take the foreign key with it.
            DB::statement('ALTER TABLE taggables ALTER COLUMN org_id SET NOT NULL');
        } else {
            Schema::table('taggables', function (Blueprint $table) {
                $table->uuid('org_id')->nullable(false)->change();
            });
        }

        Schema::table('taggables', function (Blueprint $table) {
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::table('taggables', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropIndex(['org_id']);
            $table->dropColumn('org_id');
        });
    }
};
