<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merge_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable on purpose: NULL org_id = a system field, visible to
            // every org (universal doc templates ship with system fields).
            // This is why MergeField does NOT use BelongsToOrganization —
            // its global scope would hide system rows.
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 64); // the ${key} token: /^[a-z][a-z0-9_]*$/
            $table->string('label');
            $table->string('type', 16); // text | multiline | date | list
            $table->string('field_group')->nullable(); // data-entry form grouping ("group" is reserved SQL)
            $table->text('help')->nullable();
            $table->integer('seq')->default(0);
            // D2's template upload auto-registers unknown ${keys} as drafts.
            $table->boolean('draft')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index('org_id');
            // Key uniqueness (per-org + no shadowing of system keys) is
            // enforced at validation time, not by a DB unique index —
            // soft-deleted rows must not block re-creating a key.
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_fields');
    }
};
