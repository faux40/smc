<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M1 — hours of credit live on the completion itself: class close-out
     * stamps them from the class_training snapshot; manual entry takes an
     * optional value. Backfill existing class-issued completions from
     * their snapshot rows.
     */
    public function up(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->decimal('hours', 6, 2)->nullable()->after('cert_id');
        });

        // Portable correlated-subquery backfill (sqlite + pgsql).
        DB::statement(<<<'SQL'
            UPDATE completions
            SET hours = (
                SELECT class_training.hours
                FROM class_training
                WHERE class_training.id = completions.class_training_id
            )
            WHERE class_training_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->dropColumn('hours');
        });
    }
};
