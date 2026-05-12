<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            // Timing template defaults — copied into rqmt_elements when a
            // training is added to a requirement. At least one of the three
            // T/F flags must be true (enforced by FormRequest at consumer-time).
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->foreignUuid('std_freq_id')->nullable()->constrained('std_frequencies')->nullOnDelete();
            $table->boolean('as_needed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainings');
    }
};
