<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional user-profile fields: org-chart-ish metadata (department, location,
 * job title, supervisor) + employment dates. All nullable — login/visibility
 * gating off these is deferred to a later item; for now they're just stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department')->nullable()->after('status');
            $table->string('location')->nullable()->after('department');
            $table->string('job_title')->nullable()->after('location');
            // Self-referential supervisor (another user in the same org).
            // Indexed nullable UUID rather than a DB-level FK: adding a
            // constrained FK to the existing users table forces a full SQLite
            // table rebuild (which clobbers the partial-unique email index).
            // Same-org integrity is enforced in validation; users soft-delete,
            // so a hard-delete orphan isn't a live concern.
            $table->uuid('supervisor_id')->nullable()->after('job_title')->index();
            $table->date('start_date')->nullable()->after('supervisor_id');
            $table->date('end_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['department', 'location', 'job_title', 'supervisor_id', 'start_date', 'end_date']);
        });
    }
};
