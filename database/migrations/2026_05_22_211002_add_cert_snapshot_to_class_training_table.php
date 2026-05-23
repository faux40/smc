<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the training's certificate content at attach time, so a
     * later edit to the training (or its deletion) never rewrites the certs
     * a completed class already issued.
     */
    public function up(): void
    {
        Schema::table('class_training', function (Blueprint $table) {
            $table->string('cert_title')->nullable()->after('std_freq_name');
            $table->text('cert_text')->nullable()->after('cert_title');
            $table->unsignedSmallInteger('lifespan_months')->nullable()->after('cert_text');
            $table->string('cert_code', 32)->nullable()->after('lifespan_months');
        });
    }

    public function down(): void
    {
        Schema::table('class_training', function (Blueprint $table) {
            $table->dropColumn([
                'cert_title',
                'cert_text',
                'lifespan_months',
                'cert_code',
            ]);
        });
    }
};
