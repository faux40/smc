<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference-only student counts on a class (planning + sign-in sheet
     * sizing). Deliberately NOT enforced against enrollment anywhere.
     */
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_students')->nullable()->after('total_hours');
            $table->unsignedSmallInteger('max_students')->nullable()->after('min_students');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn(['min_students', 'max_students']);
        });
    }
};
