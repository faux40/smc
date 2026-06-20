<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-topic completion result for each enrollee, kept as a
 * {class_training_id: 'pass'|'fail'|'incomplete'} map. Lets a re-close apply
 * exactly what's marked (only Pass credits; Fail/Incomplete don't) and lets
 * the modal pre-fill + the roster show the three states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->json('results')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('class_enrollments', function (Blueprint $table) {
            $table->dropColumn('results');
        });
    }
};
