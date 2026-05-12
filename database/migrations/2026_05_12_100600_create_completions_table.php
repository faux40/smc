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
            // Nullable: per v14 spec, completions can stand alone — a user
            // can complete a module without being assigned. The rqmt_element
            // link is the satisfaction target when present; absent = the
            // user just has credit for the underlying module.
            $table->foreignUuid('rqmt_element_id')->nullable()->constrained('rqmt_elements')->nullOnDelete();
            // Poly to the actual module record (Training today; future
            // Inspection / Cert / etc.). For a completion to satisfy a
            // rqmt_element it must match the element's module_type.
            $table->string('module_type');
            $table->string('module_id');
            // Satisfaction facts.
            $table->date('completion_date');
            $table->date('certification_date')->nullable();
            $table->date('expire_date')->nullable();
            // External cert/license number (e.g., a forklift cert serial).
            $table->string('cert_ident')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['user_id', 'rqmt_element_id']);
            $table->index(['module_type', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completions');
    }
};
