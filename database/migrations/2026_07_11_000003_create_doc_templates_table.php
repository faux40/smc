<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL org_id = system template (universal library), same
            // two-scope pattern as merge_fields.
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('original_filename');
            $table->string('extension', 8); // docx | odt
            $table->string('path'); // on the linode disk
            $table->unsignedBigInteger('size');
            // Distinct ${keys} found at upload (modifiers stripped).
            $table->json('placeholders');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('prev_version_id')->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // replaced versions are soft-deleted, files kept
            $table->index('org_id');
        });

        // Self-referencing FK added AFTER create (same pattern as comments.
        // parent_id): inside the create blueprint, Postgres receives the FK
        // ALTER before the fluent primary-key ALTER and rejects it ("no
        // unique constraint matching given keys for referenced table").
        Schema::table('doc_templates', function (Blueprint $table) {
            $table->foreign('prev_version_id')->references('id')->on('doc_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_templates');
    }
};
