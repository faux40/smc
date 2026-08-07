<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The refresher guard: a class flagged `requires_prior_completion` expects
 * everyone on its roster to already hold a completion of each topic's
 * training. Drives a SOFT roster warning only — enrollment is never blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->boolean('requires_prior_completion')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table): void {
            $table->dropColumn('requires_prior_completion');
        });
    }
};
