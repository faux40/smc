<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training System (v16) — a scheduled class that, once completed, bulk-creates
 * completions for its passed enrollees against its associated trainings.
 * Phase A: scheduling + roster only (status stays 'scheduled').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->date('scheduled_date');
            // Event-level details, shown on certificates and sheets.
            // start_time/end_time are optional "HH:MM" strings; address is
            // pre-filled from a training's default when the first topic is
            // attached; show_signature toggles the script-font signature.
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('location')->nullable();
            $table->text('address')->nullable();
            $table->string('instructor')->nullable();
            $table->boolean('show_signature')->default(false);
            $table->decimal('total_hours', 6, 2)->nullable();
            $table->text('notes')->nullable();
            // 'scheduled' until the instructor closes it out (Phase B), then
            // 'completed' and view-only.
            $table->string('status')->default('scheduled');
            // Set at close (Phase B); defaults to scheduled_date. The credit
            // date shared by every generated completion.
            $table->date('completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
