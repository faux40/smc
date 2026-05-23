<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a class is closed out, each passed student × topic gets a
     * standalone completion. These columns let that completion render a
     * certificate: `cert_id` is the issued id; `class_training_id` links back
     * to the snapshot (and through it the class) for the cert content.
     *
     * `class_training_id` is a plain indexed UUID, not a constrained FK —
     * adding a constrained FK to the existing completions table forces a
     * SQLite table rebuild in tests (per prior schema notes). Integrity is
     * enforced in the close-out logic.
     */
    public function up(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->string('cert_id')->nullable()->after('cert_ident');
            $table->uuid('class_training_id')->nullable()->after('cert_id');
            $table->index('class_training_id');
        });
    }

    public function down(): void
    {
        Schema::table('completions', function (Blueprint $table) {
            $table->dropIndex(['class_training_id']);
            $table->dropColumn(['cert_id', 'class_training_id']);
        });
    }
};
