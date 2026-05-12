<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many between completions and rqmt_elements (per v15 spec —
     * the "completion-elements" table in slide 1). One completion can
     * satisfy several elements (and therefore several Requirements).
     *
     * Application layer requires ≥1 row per completion at create/update;
     * the schema itself permits orphans so the v15 "credit for unassigned"
     * path stays open without a future migration.
     */
    public function up(): void
    {
        Schema::create('completion_elements', function (Blueprint $table) {
            $table->foreignUuid('completion_id')->constrained('completions')->cascadeOnDelete();
            // Hard-delete of an element drops the link — completion stays,
            // may end up orphaned (no links left). Soft-delete on rqmt_elements
            // is the normal path so this rarely fires.
            $table->foreignUuid('rqmt_element_id')->constrained('rqmt_elements')->cascadeOnDelete();

            $table->primary(['completion_id', 'rqmt_element_id']);
            $table->index('rqmt_element_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completion_elements');
    }
};
