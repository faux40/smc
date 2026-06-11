<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * J4 — the daily watchdog now tracks TrainingAssignment statuses, so
     * the edge-detection state keys on training_assignment_id. Dev/demo
     * data only: truncate and rebuild, no backfill (J0.3).
     *
     * Steps are separate Schema::table calls — sqlite (test DB) rebuilds
     * the table per alter and chokes if an index still references a
     * column being dropped.
     */
    public function up(): void
    {
        DB::table('assignment_notification_states')->truncate();

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->dropUnique('assignment_notification_states_assignment_id_unique');
        });

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_id');
        });

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->foreignUuid('training_assignment_id')
                ->after('org_id')
                ->constrained('training_assignments')
                ->cascadeOnDelete();
            $table->unique('training_assignment_id');
        });
    }

    public function down(): void
    {
        DB::table('assignment_notification_states')->truncate();

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->dropUnique('assignment_notification_states_training_assignment_id_unique');
        });

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('training_assignment_id');
        });

        Schema::table('assignment_notification_states', function (Blueprint $table) {
            $table->foreignUuid('assignment_id')
                ->after('org_id')
                ->unique()
                ->constrained('assignments')
                ->cascadeOnDelete();
        });
    }
};
