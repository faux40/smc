<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Event-level venue for a class, shown on certificates and sheets.
     * (Trainer = the existing `instructor`; company location = `location`.)
     * Pre-filled from a training's defaults when the first topic is attached.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->string('training_location')->nullable()->after('location');
            $table->text('training_address')->nullable()->after('training_location');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['training_location', 'training_address']);
        });
    }
};
