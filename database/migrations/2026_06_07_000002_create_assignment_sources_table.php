<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('training_assignment_id')
                ->constrained('training_assignments')
                ->cascadeOnDelete();
            // Polymorphic source: null/null = direct assignment;
            // Requirement = assigned via a requirement.
            $table->string('sourceable_type')->nullable();
            $table->uuid('sourceable_id')->nullable();
            // Audit timestamps — added_at is when the source was attached,
            // removed_at when it was withdrawn (null = still active).
            $table->timestamp('added_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();

            $table->index('training_assignment_id');
            $table->index(['sourceable_type', 'sourceable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_sources');
    }
};
