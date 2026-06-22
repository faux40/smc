<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalized compliance status on each (user, training) assignment.
 *
 * Status is otherwise a pure function of the already-flattened columns
 * (as_needed_only / last_completed_at / expires_at) + the org's amber window,
 * but recomputing it on every read is wasteful at scale. We store it instead,
 * maintained in realtime by RecalculateTrainingStatus (on any compliance-
 * affecting change) and reconciled daily by the scan-due-states watchdog
 * (which catches date-crossings that fire no event). ComplianceQuery groups by
 * this column. The backfill below seeds existing rows so reads are correct
 * immediately after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_assignments', function (Blueprint $table) {
            $table->string('status')->nullable()->after('as_needed_only');
            $table->index(['org_id', 'status']);
            $table->index(['org_id', 'training_id']);
        });

        // Seed existing rows per org (each org has its own amber window, stored
        // in the training_thresholds JSON). The CASE mirrors
        // TrainingStatusService::statusFor() exactly.
        foreach (Organization::all() as $org) {
            $window = $org->expiringSoonDays();
            $today = Carbon::now()->startOfDay()->toDateString();
            $boundary = Carbon::now()->startOfDay()->addDays($window)->toDateString();

            DB::update(
                <<<'SQL'
                UPDATE training_assignments SET status = CASE
                    WHEN as_needed_only THEN 'as_needed'
                    WHEN last_completed_at IS NULL THEN 'not_started'
                    WHEN expires_at IS NOT NULL AND expires_at < ? THEN 'overdue'
                    WHEN expires_at IS NOT NULL AND expires_at >= ? AND expires_at <= ? THEN 'due_soon'
                    ELSE 'current'
                END
                WHERE org_id = ?
                SQL,
                [$today, $today, $boundary, $org->id],
            );
        }
    }

    public function down(): void
    {
        Schema::table('training_assignments', function (Blueprint $table) {
            $table->dropIndex(['org_id', 'status']);
            $table->dropIndex(['org_id', 'training_id']);
            $table->dropColumn('status');
        });
    }
};
