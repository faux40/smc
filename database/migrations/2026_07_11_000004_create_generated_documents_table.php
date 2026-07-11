<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // Template row may be soft-deleted (replaced version) — the
            // reference stays for history; null only on hard delete.
            $table->foreignUuid('doc_template_id')->nullable()->constrained('doc_templates')->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The variation the document was generated for ('' = org-wide).
            $table->string('location')->default('');
            $table->string('department')->default('');
            $table->string('status', 16)->default('queued'); // queued|processing|done|failed
            $table->text('error')->nullable();
            $table->string('filename'); // display base name (no extension)
            $table->string('merged_path')->nullable(); // linode: editable DOCX/ODT
            $table->string('pdf_path')->nullable(); // linode: client-ready PDF
            // The exact fields/listRows merged — audit + reproducibility
            // (the demo built this as xml_source_data then never saved it).
            $table->json('merge_snapshot')->nullable();
            $table->timestamps();
            $table->index('org_id');
            $table->index(['org_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
