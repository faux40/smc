<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            // The sheet this training's cards print onto by default — the
            // companion to card_template_id, which says what is printed.
            // "Which stock is right for First Aid?" is a property of the
            // training, not a question to answer again at every print.
            // NULL = no default; the print dialog asks.
            //
            // The dialog still overrides per run, because what is loaded in
            // the printer today is a fact about today.
            $table->foreignUuid('card_stock_id')
                ->nullable()
                ->after('card_template_id')
                ->constrained('card_stocks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_stock_id');
        });
    }
};
