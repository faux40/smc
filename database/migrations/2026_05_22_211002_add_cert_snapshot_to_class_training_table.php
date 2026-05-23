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
            $table->string('cert_text_line_1')->nullable()->after('cert_title');
            $table->string('cert_text_line_2')->nullable()->after('cert_text_line_1');
            $table->string('cert_text_line_3')->nullable()->after('cert_text_line_2');
            $table->string('cert_text_line_4')->nullable()->after('cert_text_line_3');
            $table->unsignedSmallInteger('lifespan_months')->nullable()->after('cert_text_line_4');
            $table->string('cert_code', 32)->nullable()->after('lifespan_months');
            $table->boolean('show_signature_on_cert')->default(false)->after('cert_code');
        });
    }

    public function down(): void
    {
        Schema::table('class_training', function (Blueprint $table) {
            $table->dropColumn([
                'cert_title',
                'cert_text_line_1',
                'cert_text_line_2',
                'cert_text_line_3',
                'cert_text_line_4',
                'lifespan_months',
                'cert_code',
                'show_signature_on_cert',
            ]);
        });
    }
};
