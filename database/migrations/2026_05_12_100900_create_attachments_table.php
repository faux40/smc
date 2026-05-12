<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('attachable_type');
            $table->string('attachable_id');
            // Preserve uploader even after user soft-delete (same logic as
            // comments author): keep org history intact.
            $table->foreignUuid('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('filename');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // Filesystem disk + path; the actual upload pipeline (Linode S3)
            // gets wired when the upload controller lands in a later phase.
            $table->string('disk');
            $table->string('path');
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
