<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            // Optional hex `#RRGGBB` overriding the derived text color
            // used by <TagPill>. Nullable so existing tags keep the
            // pre-feature render (text color derived from `color`).
            $table->string('font_color')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('font_color');
        });
    }
};
