<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Event-level details for a class, shown on certificates and sheets.
     * (Trainer = the existing `instructor`; venue = `location`.) `address`
     * is pre-filled from a training's default when the first topic is
     * attached. `start_time`/`end_time` are optional "HH:MM" strings.
     * `show_signature` toggles the script-font signature on this class's
     * certificates.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->text('address')->nullable()->after('location');
            $table->string('start_time', 5)->nullable()->after('scheduled_date');
            $table->string('end_time', 5)->nullable()->after('start_time');
            $table->boolean('show_signature')->default(false)->after('instructor');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['address', 'start_time', 'end_time', 'show_signature']);
        });
    }
};
