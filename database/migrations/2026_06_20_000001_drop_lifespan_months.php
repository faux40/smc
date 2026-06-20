<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A training's lifespan is its frequency: real expiry is computed from the
 * frequency's repeat_days (class close stamps completion.expire_date; the
 * assignment engine derives expires_at). The separate lifespan_months field
 * was redundant and only fed a display fallback (now switched to repeat_days),
 * so drop it from both the training template and the per-class snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('lifespan_months');
        });

        Schema::table('class_training', function (Blueprint $table) {
            $table->dropColumn('lifespan_months');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->unsignedSmallInteger('lifespan_months')->nullable();
        });

        Schema::table('class_training', function (Blueprint $table) {
            $table->unsignedSmallInteger('lifespan_months')->nullable();
        });
    }
};
