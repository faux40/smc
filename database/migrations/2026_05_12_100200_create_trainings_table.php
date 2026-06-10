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
            // Short alias (e.g. "FallPro" for "Fall Protection") — optional.
            $table->string('nickname')->nullable();
            $table->text('description')->nullable();
            // Timing template defaults — copied into rqmt_elements when a
            // training is added to a requirement. At least one of the three
            // T/F flags must be true (enforced by FormRequest at consumer-time).
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->foreignUuid('std_freq_id')->nullable()->constrained('std_frequencies')->nullOnDelete();
            $table->boolean('as_needed')->default(false);
            // Typical duration — pre-fills the per-class hours when this topic
            // is added to a class (still editable per class).
            $table->decimal('default_hours', 5, 2)->nullable();
            // Certificate content defaults, copied onto the class_training
            // snapshot when the topic is attached to a class, then used to
            // render that class's certs. cert_text is Markdown; the default_*
            // fields pre-fill the class's event-level fields on attach.
            $table->string('cert_title')->nullable();
            $table->text('cert_text')->nullable();
            $table->unsignedSmallInteger('lifespan_months')->nullable();
            $table->string('cert_code', 32)->nullable();
            $table->string('default_trainer')->nullable();
            $table->string('default_location')->nullable();
            $table->text('default_address')->nullable();
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
