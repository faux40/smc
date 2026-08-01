<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_print_runs', function (Blueprint $table) {
            // C6b: a proof run prints only the first card — the real
            // pipeline, sliced to one, to check fit and position before
            // committing a sheet of purchased stock. On the run rather than
            // the request because the queued job reads the row.
            $table->boolean('proof')->default(false)->after('include_backs');
        });
    }

    public function down(): void
    {
        Schema::table('card_print_runs', function (Blueprint $table) {
            $table->dropColumn('proof');
        });
    }
};
