<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('commentable_type');
            // String for mixed-PK morphs.
            $table->string('commentable_id');
            // No CASCADE on author_id — Phase 5.3 decision: preserve author
            // even after user soft-delete so the org history is intact.
            // The user is soft-deleted, not hard-gone.
            $table->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
