<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two composite indexes on `completions` (F3):
 *
 * - completions_org_completion_date_idx (org_id, completion_date) — serves the
 *   default completions list (CompletionsController@index: org filter +
 *   ORDER BY completion_date DESC), the dashboard recent-completions widget
 *   (DashboardController@recentCompletions: org filter + ORDER BY
 *   completion_date DESC), and the reports completion query
 *   (ReportsController@completionsQuery: org filter + from/to range on
 *   completion_date + ORDER BY completion_date DESC).
 *
 * - completions_user_module_date_idx (user_id, module_type, module_id,
 *   completion_date) — serves the "latest completion for a (user, module)"
 *   lookups: RecalculateTrainingStatus::handle() filters
 *   user_id + module_type + module_id and orders by completion_date DESC to
 *   find the latest completion; ComplianceQuery::notRequired() /
 *   notRequiredFactsForTraining() run a correlated NOT EXISTS on
 *   c2.user_id = c.user_id AND c2.module_id = c.module_id AND
 *   c2.module_type = Training::class AND c2.completion_date > c.completion_date
 *   to find whether a later completion of the same (user, module) exists.
 *   module_type sits before module_id to mirror the morph column order used by
 *   the table's own ['module_type', 'module_id'] index; all three of user_id /
 *   module_type / module_id are always equality predicates at every call site,
 *   so their relative order doesn't affect index usability — completion_date
 *   is last because it's the only range/ORDER BY column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->index(['org_id', 'completion_date'], 'completions_org_completion_date_idx');
            $table->index(['user_id', 'module_type', 'module_id', 'completion_date'], 'completions_user_module_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->dropIndex('completions_org_completion_date_idx');
            $table->dropIndex('completions_user_module_date_idx');
        });
    }
};
