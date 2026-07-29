<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL org_id = system template, same two-scope pattern as
            // doc_templates and card_stocks.
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('original_filename');
            $table->string('extension', 8); // pptx | odp
            $table->string('path'); // on the linode disk
            $table->unsignedBigInteger('size');
            // Distinct ${keys} found at upload. Unlike doc_templates these
            // are NOT auto-registered as org merge fields: a card's keys come
            // from the class/user catalogue and the training's own custom
            // fields, so an unknown key here is a typo, not a new field.
            $table->json('placeholders');
            // Font families the template asks for, and the subset the
            // converter cannot honour (it substitutes and the card re-flows).
            // Stored so the warning survives without re-opening the archive.
            $table->json('fonts');
            $table->json('unsupported_fonts');
            // 1 = single-sided, 2 = front and back. Enforced at upload.
            $table->unsignedSmallInteger('slide_count');
            // The card's size in points, READ from the slide dimensions —
            // never typed. Checked against the chosen stock's cell at print
            // time so a mismatch warns instead of silently scaling.
            $table->decimal('card_width', 9, 3);
            $table->decimal('card_height', 9, 3);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('prev_version_id')->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // replaced versions soft-deleted, files kept
            $table->index('org_id');
        });

        // Self-referencing FK added AFTER create (same reason as
        // doc_templates): inside the create blueprint Postgres receives the
        // FK ALTER before the fluent primary-key ALTER and rejects it.
        Schema::table('card_templates', function (Blueprint $table) {
            $table->foreign('prev_version_id')->references('id')->on('card_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_templates');
    }
};
