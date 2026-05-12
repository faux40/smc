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
            // Optional self-FK column for future threading. Column declared
            // here without the FK; constraint added in a follow-up
            // Schema::table call below because Postgres can't resolve a
            // self-FK to the table being created inside the same statement.
            $table->uuid('parent_id')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['commentable_type', 'commentable_id']);
        });

        // v14 spec doesn't ship threaded comments, but the schema supports
        // adding threading as a UX layer with no migration. nullOnDelete: if
        // a parent is ever hard-deleted, children stay visible as top-level
        // rather than vanishing. (Soft-delete is the normal path; FK action
        // only fires on hard-delete.)
        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('comments')->nullOnDelete();
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
