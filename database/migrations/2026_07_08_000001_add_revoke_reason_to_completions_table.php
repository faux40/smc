<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            // Why a revoked certificate was pulled. Retained alongside
            // deleted_at on the soft-deleted row so a future audit view can
            // explain the removal. Distinct from `notes` so the reason is
            // unambiguous to auditors.
            $table->string('revoke_reason', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->dropColumn('revoke_reason');
        });
    }
};
