<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            // Timing flattened onto the assignment so the per-(user, req)
            // schedule is independent of later edits to the requirement /
            // rqmt_elements. Mutex on initial_only ⇄ repeating enforced
            // by the FormRequest when the consumer phase lands.
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->foreignUuid('std_freq_id')->nullable()->constrained('std_frequencies')->nullOnDelete();
            $table->boolean('as_needed')->default(false);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
