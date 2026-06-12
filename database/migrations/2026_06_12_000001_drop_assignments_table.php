<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * J5 — the legacy user×requirement assignments table is retired.
     * training_assignments + assignment_sources are the only persisted
     * assignment shape; every reader/writer was ported in J2–J5.
     * Dev/demo data only (J0.3): nothing preserved.
     */
    public function up(): void
    {
        Schema::dropIfExists('assignments');
    }

    /**
     * Recreates the table's final shape (post timing-drop, with the
     * partial active-unique index) so down() is runnable, but the data
     * is gone — this rollback exists for schema symmetry only.
     */
    public function down(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('requirement_id')->constrained('requirements')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index('user_id');
            $table->index('requirement_id');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->unique(['user_id', 'requirement_id'], 'assignments_active_unique');
        });
    }
};
