<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crosswalk between TrainingWise legacy ids and the new SMC rows they were
 * imported as. Powers the tw:migrate command's idempotency (re-run reuses
 * existing rows) and lets the separate attachment phase resolve a legacy
 * class id to its new TrainingClass uuid. Side table — safe to drop once
 * the one-off TrainingWise migration is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_tw_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('entity', 40);          // org | std_freq | user | training | class | class_training | completion | attachment
            $table->unsignedBigInteger('tw_id');   // the TrainingWise primary key
            $table->uuid('new_id');                // the SMC row it became
            $table->uuid('new_org_id')->nullable();
            $table->timestamps();

            $table->unique(['entity', 'tw_id']);
            $table->index('new_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_tw_map');
    }
};
