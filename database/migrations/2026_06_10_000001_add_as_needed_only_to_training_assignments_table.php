<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_assignments', function (Blueprint $table) {
            // Flattened by RecalculateTrainingStatus (J3): true when every
            // active source's timing is as-needed — visible on the user but
            // never scheduled or required, so it gets its own status bucket.
            $table->boolean('as_needed_only')->default(false)->after('last_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_assignments', function (Blueprint $table) {
            $table->dropColumn('as_needed_only');
        });
    }
};
