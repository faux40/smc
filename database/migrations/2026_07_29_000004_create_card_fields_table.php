<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Denormalised from the training so print-time queries can scope
            // by org directly (and BelongsToOrganization can do its job);
            // cascade follows the org teardown like every other tenant table.
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // Definitions belong to the TRAINING: a class inherits the fields
            // of each topic it teaches, so "First Aid always needs a trainer
            // id" is stated once.
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            // The merge key as it appears in the template: ${trainer_id}.
            // Grammar enforced in the request (^[a-z][a-z0-9_]*$) — same as
            // merge_fields, so a card key and a doc key look alike.
            $table->string('key', 64);
            $table->string('label');
            // short = one-line plain text (100 chars); rich = markdown subset,
            // converted to PPTX/ODP runs in C5.
            $table->string('type', 16);
            $table->text('default_value')->nullable();
            $table->unsignedSmallInteger('seq')->default(0);
            $table->timestamps();
            // No softDeletes on purpose: removing a definition is a hard
            // delete (its class values cascade with it, warned by a count in
            // the UI). A soft-deleted row would also make the unique index
            // below refuse an obvious key reuse.
            $table->unique(['training_id', 'key']);
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_fields');
    }
};
