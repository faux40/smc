<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text notes on a user. Editable on the detail page, and the
     * destination for values discarded by the combine-users (merge) tool —
     * when two records are folded together the loser's conflicting profile
     * fields are appended here as an audit block rather than silently dropped.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
