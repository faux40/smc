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
     * `default_training_location` / `default_training_address` pre-fill the
     * class's event-level fields when the topic is attached.
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->string('cert_title')->nullable()->after('default_hours');
            $table->string('cert_text_line_1')->nullable()->after('cert_title');
            $table->string('cert_text_line_2')->nullable()->after('cert_text_line_1');
            $table->string('cert_text_line_3')->nullable()->after('cert_text_line_2');
            $table->string('cert_text_line_4')->nullable()->after('cert_text_line_3');
            $table->unsignedSmallInteger('lifespan_months')->nullable()->after('cert_text_line_4');
            $table->string('cert_code', 32)->nullable()->after('lifespan_months');
            $table->boolean('show_signature_on_cert')->default(false)->after('cert_code');
            $table->string('default_trainer')->nullable()->after('show_signature_on_cert');
            $table->string('default_training_location')->nullable()->after('default_trainer');
            $table->text('default_training_address')->nullable()->after('default_training_location');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn([
                'cert_title',
                'cert_text_line_1',
                'cert_text_line_2',
                'cert_text_line_3',
                'cert_text_line_4',
                'lifespan_months',
                'cert_code',
                'show_signature_on_cert',
                'default_trainer',
                'default_training_location',
                'default_training_address',
            ]);
        });
    }
};
