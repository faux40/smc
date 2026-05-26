<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the per-assignment timing snapshot.
 *
 * A requirement is a group of RqmtElements, each carrying its own timing
 * (initial_only / repeating / std_freq_id / as_needed). An assignment spans
 * the whole requirement, so a single timing on the assignment can't
 * represent elements with differing frequencies — and it never drove
 * compliance anyway: UserComplianceCalculator computes status purely from
 * element timing. These columns were display-only, so they go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('std_freq_id');
            $table->dropColumn(['initial_only', 'repeating', 'as_needed']);
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->foreignUuid('std_freq_id')->nullable()->constrained('std_frequencies')->nullOnDelete();
            $table->boolean('as_needed')->default(false);
        });
    }
};
