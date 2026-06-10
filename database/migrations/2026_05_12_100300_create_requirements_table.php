<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('org_id');
        });

        // Block duplicate requirement names within an org (case-insensitive).
        // Indexes lower(name) so "Forklift"/"forklift" collide; partial on
        // deleted_at IS NULL so a soft-deleted name frees for reuse. Expression
        // + partial index syntax works on Postgres (prod) and SQLite (tests).
        DB::statement(
            'CREATE UNIQUE INDEX requirements_org_name_unique ON requirements (org_id, lower(name)) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS requirements_org_name_unique');
        Schema::dropIfExists('requirements');
    }
};
