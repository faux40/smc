<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training hierarchy: a "higher" training satisfies a "lower" one.
 *
 * `trainings.superseded_by_id` is a single upward pointer — a ladder is a
 * chain of them (Authorized → Competent → Qualified), transitive all the way
 * up. Resolution happens inside RecalculateTrainingStatus and lands on the
 * materialized assignment columns, so nothing pays a recursive query at read
 * time. `training_assignments.satisfied_via_training_id` records which
 * covering training's credential is doing the satisfying (null = its own) —
 * the audit answer, materialized onto every consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->foreignUuid('superseded_by_id')
                ->nullable()
                ->constrained('trainings')
                ->nullOnDelete();
        });

        Schema::table('training_assignments', function (Blueprint $table) {
            $table->foreignUuid('satisfied_via_training_id')
                ->nullable()
                ->constrained('trainings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_id');
        });

        Schema::table('training_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('satisfied_via_training_id');
        });
    }
};
