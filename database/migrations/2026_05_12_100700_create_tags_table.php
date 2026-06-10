<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            // Optional hex color (e.g. #FF8800). No palette enum — caller's
            // choice. Nullable so blank tags work.
            $table->string('color')->nullable();
            // Optional hex #RRGGBB overriding the derived text color used by
            // <TagPill>. Nullable so tags keep the pre-feature render.
            $table->string('font_color')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignUuid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('taggable_type');
            // String to support mixed-PK morphs (matches the rqmt_elements +
            // completions poly columns from 5.2).
            $table->string('taggable_id');

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
