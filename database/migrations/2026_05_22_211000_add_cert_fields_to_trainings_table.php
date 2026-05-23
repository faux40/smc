<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Certificate content defaults for a training topic. These are copied
     * onto the class_training snapshot when the topic is added to a class,
     * then used to render that class's certificates. `default_trainer` /
     * `default_location` / `default_address` pre-fill the class's event-level
     * fields when the topic is attached. `cert_text` is Markdown (line breaks
     * + light styling), rendered on the certificate.
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('cert_title')->nullable()->after('default_hours');
            $table->text('cert_text')->nullable()->after('cert_title');
            $table->unsignedSmallInteger('lifespan_months')->nullable()->after('cert_text');
            $table->string('cert_code', 32)->nullable()->after('lifespan_months');
            $table->string('default_trainer')->nullable()->after('cert_code');
            $table->string('default_location')->nullable()->after('default_trainer');
            $table->text('default_address')->nullable()->after('default_location');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn([
                'cert_title',
                'cert_text',
                'lifespan_months',
                'cert_code',
                'default_trainer',
                'default_location',
                'default_address',
            ]);
        });
    }
};
