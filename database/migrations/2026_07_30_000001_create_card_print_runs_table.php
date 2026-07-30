<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_print_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // The class is carried alongside the topic so a class's runs can be
            // listed without joining through class_training.
            $table->foreignUuid('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignUuid('class_training_id')->constrained('class_training')->cascadeOnDelete();
            // Design + stock are nulled rather than cascaded: a finished run is
            // a record of what was printed, and it stays explicable even if the
            // template or stock is later removed.
            $table->foreignUuid('card_template_id')->nullable()->constrained('card_templates')->nullOnDelete();
            $table->foreignUuid('card_stock_id')->nullable()->constrained('card_stocks')->nullOnDelete();
            // Which version of the design actually printed — templates version
            // on replace, so "why does this card differ" needs this.
            $table->unsignedInteger('template_version')->nullable();

            // 1-based, as the UI counts cells; the geometry converts.
            $table->unsignedSmallInteger('start_cell')->default(1);
            $table->boolean('include_backs')->default(false);

            $table->string('status', 16)->default('queued');
            $table->text('error')->nullable();
            $table->unsignedInteger('card_count')->nullable();
            $table->unsignedInteger('sheet_count')->nullable();
            // Both sheet PDFs land in the class's documents; the paths are kept
            // here too so a run can point at exactly what it produced.
            $table->string('front_path')->nullable();
            $table->string('back_path')->nullable();
            // Shared YYYYmmdd_HHMM stamp in both filenames, so a fronts file
            // can never be paired with another run's backs.
            $table->string('run_stamp', 32)->nullable();

            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No softDeletes: the outputs are regenerable and the filed
            // attachments are the lasting artefact (same call as
            // generated_documents).
            $table->index(['class_id', 'created_at']);
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_print_runs');
    }
};
