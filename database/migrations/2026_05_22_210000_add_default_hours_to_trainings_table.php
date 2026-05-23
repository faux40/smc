<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A training topic's typical duration. Used to pre-fill the per-class
     * hours when the topic is added to a class (still editable per class).
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->decimal('default_hours', 5, 2)->nullable()->after('as_needed');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn('default_hours');
        });
    }
};
