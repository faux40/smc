<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users enrolled in a class. At close (Phase B) the instructor marks each
 * passed/incomplete (+ per-enrollee notes); only 'passed' enrollees get
 * generated completions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // enrolled (roster) → passed | incomplete (set at close).
            $table->string('status')->default('enrolled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['class_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_enrollments');
    }
};
