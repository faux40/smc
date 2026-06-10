<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trainings attached to a class. NOT a bare pivot: it snapshots the training's
 * relevant fields at attach time so later edits to the Training don't rewrite
 * a historical class. Carries the allocated hours + (Phase B) the computed
 * expire_date for completions generated against this training.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_training', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('classes')->cascadeOnDelete();
            // Lineage to the live training (nullable: keep the row if the
            // training is later deleted — the snapshot preserves history).
            $table->foreignUuid('training_id')->nullable()->constrained('trainings')->nullOnDelete();

            // Snapshot copied from the training on attach.
            $table->string('training_name');
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->boolean('as_needed')->default(false);
            $table->integer('repeat_days')->nullable();
            $table->string('std_freq_name')->nullable();

            // Cert content snapshot at attach time, so a later edit to the
            // training (or its deletion) never rewrites certs an issued class
            // already produced.
            $table->string('cert_title')->nullable();
            $table->text('cert_text')->nullable();
            $table->unsignedSmallInteger('lifespan_months')->nullable();
            $table->string('cert_code', 32)->nullable();

            // Allocated class hours for this training (no sum validation).
            $table->decimal('hours', 6, 2)->nullable();
            // Set at close (Phase B): the expiry shared by completions for this
            // training, computed from the snapshot freq, instructor-overridable.
            $table->date('expire_date')->nullable();

            $table->timestamps();

            $table->index('class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_training');
    }
};
