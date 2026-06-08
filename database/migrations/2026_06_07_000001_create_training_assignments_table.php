<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            // Snapshot of training name at assignment time for display stability.
            $table->string('name');
            // Pre-computed from the most recent Completion — updated by the
            // CompletionObserver whenever a completion is saved or deleted.
            $table->date('expires_at')->nullable();
            $table->date('last_completed_at')->nullable();
            $table->timestamps();

            $table->index('org_id');
            $table->index('user_id');
            // One row per (user, training) — multiple sources attach via
            // assignment_sources, not by creating duplicate rows here.
            $table->unique(['user_id', 'training_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_assignments');
    }
};
