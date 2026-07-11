<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('merge_field_id')->constrained('merge_fields')->cascadeOnDelete();
            // '' = "not specific to a location/department" (the org-wide
            // default row). Empty string instead of NULL so the unique
            // index below works identically on Postgres and sqlite
            // (NULLs are mutually distinct in unique indexes).
            $table->string('location')->default('');
            $table->string('department')->default('');
            // string for text/multiline/date fields; array for list fields.
            $table->json('value')->nullable();
            $table->timestamps();
            // No softDeletes on purpose: "clear override" is a hard delete;
            // a soft-deleted row would collide with re-setting the value.
            $table->unique(['org_id', 'merge_field_id', 'location', 'department'], 'merge_values_variation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_values');
    }
};
