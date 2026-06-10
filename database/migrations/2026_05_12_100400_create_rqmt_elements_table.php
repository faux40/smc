<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rqmt_elements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('requirement_id')->constrained('requirements')->cascadeOnDelete();
            // Poly to a module (Training today; future: Inspection, Cert, etc.).
            // module_id is `string` to support future modules with non-UUID
            // PKs without a schema migration. Module table CASCADE-delete is
            // handled at the model layer when needed.
            $table->string('module_type');
            $table->string('module_id');
            // Timing fields copied from the module template on create; editable
            // per-element. Mutex on initial_only ⇄ repeating enforced by the
            // FormRequest when the consumer phase lands.
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('initial_only')->default(false);
            $table->boolean('repeating')->default(false);
            $table->foreignUuid('std_freq_id')->nullable()->constrained('std_frequencies')->nullOnDelete();
            $table->boolean('as_needed')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
            $table->index(['module_type', 'module_id']);
            $table->index('requirement_id');
        });

        // Block binding the same module to a requirement twice. Partial unique
        // on (requirement_id, module_type, module_id) for non-deleted rows, so
        // soft-deleting a binding frees it to be re-added. Works on Postgres
        // (prod) + SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX rqmt_elements_module_unique ON rqmt_elements (requirement_id, module_type, module_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rqmt_elements_module_unique');
        Schema::dropIfExists('rqmt_elements');
    }
};
