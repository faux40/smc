<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable on purpose: NULL org_id = a system stock (the common
            // purchased layouts), visible to every org. Same two-scope shape
            // as merge_fields, so CardStock does NOT use
            // BelongsToOrganization — its global scope would hide system rows.
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('name');

            // All measurements are POINTS (1/72in), origin at the page's
            // top-left. Points are the unit PDF imposition works in, so the
            // print path never converts; the UI does inches/mm for entry.
            $table->decimal('page_width', 9, 3);
            $table->decimal('page_height', 9, 3);
            // *_count rather than columns/rows: both are SQL keywords in some
            // dialects, and this table's identifiers end up in raw-ish
            // imposition queries later.
            $table->unsignedSmallInteger('column_count');
            $table->unsignedSmallInteger('row_count');
            // The purchased card's own size — printed on the packaging, and
            // what the uploaded template's slide size is checked against.
            $table->decimal('card_width', 9, 3);
            $table->decimal('card_height', 9, 3);
            // Page edge to the first cell.
            $table->decimal('margin_top', 9, 3)->default(0);
            $table->decimal('margin_left', 9, 3)->default(0);
            // Space between cards; the spacing tweak a user tunes when a test
            // print drifts. Zero when the cards butt together.
            $table->decimal('gutter_x', 9, 3)->default(0);
            $table->decimal('gutter_y', 9, 3)->default(0);
            // Whole-sheet calibration nudge for a specific printer's drift.
            // Reserved for the precision pass (C6); unused until then.
            $table->decimal('offset_x', 9, 3)->default(0);
            $table->decimal('offset_y', 9, 3)->default(0);
            // How the printer flips duplex, so a 2-slide (front/back)
            // template's backs land on the right cards. NULL = single-sided
            // stock / not configured.
            $table->string('duplex_flip', 16)->nullable(); // long_edge | short_edge
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_stocks');
    }
};
