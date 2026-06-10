<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Poly to the actual module record (Training today; future
            // Inspection / Cert / etc.). The rqmt_element links live in
            // the `completion_elements` pivot — one completion can
            // satisfy many elements (and therefore many Requirements).
            $table->string('module_type');
            $table->string('module_id');
            // Satisfaction facts.
            $table->date('completion_date');
            $table->date('certification_date')->nullable();
            $table->date('expire_date')->nullable();
            // External cert/license number (e.g., a forklift cert serial).
            $table->string('cert_ident')->nullable();
            // Class close-out cert fields. cert_id = issued certificate id;
            // class_training_id links back to the snapshot (and through it the
            // class) for cert content. Plain indexed UUID, not a constrained FK
            // — a constrained FK forces a SQLite table rebuild in tests;
            // integrity is enforced in the close-out logic.
            $table->string('cert_id')->nullable();
            $table->uuid('class_training_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index('user_id');
            $table->index(['module_type', 'module_id']);
            $table->index('class_training_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completions');
    }
};
