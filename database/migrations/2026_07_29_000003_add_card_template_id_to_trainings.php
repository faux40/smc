<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // The card design this training's classes print by default, so a
            // Manager closing out a class doesn't have to know which one is
            // right. NULL = no card (the built-in SMC certificate only).
            //
            // nullOnDelete covers a hard delete; the controller detaches on
            // the soft delete that the UI actually performs, and re-points
            // the assignment when a template is REPLACED with a new version.
            $table->foreignUuid('card_template_id')
                ->nullable()
                ->after('cert_code')
                ->constrained('card_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_template_id');
        });
    }
};
