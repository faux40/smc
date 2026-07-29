<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_training_card_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            // Values hang off the class TOPIC, not the class: one class can
            // teach First Aid and Forklift, each with its own answers.
            $table->foreignUuid('class_training_id')->constrained('class_training')->cascadeOnDelete();
            $table->foreignUuid('card_field_id')->constrained('card_fields')->cascadeOnDelete();
            // Nullable / absent both mean "fall back to the field's default"
            // — the resolution ladder (value → training default → blank)
            // lives with the merge in C4.
            $table->text('value')->nullable();
            $table->timestamps();
            // No softDeletes, same reasoning as merge_values: clearing an
            // answer is a hard delete, and a tombstone would collide with
            // re-entering it.
            $table->unique(['class_training_id', 'card_field_id'], 'card_values_topic_field_unique');
            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_training_card_values');
    }
};
