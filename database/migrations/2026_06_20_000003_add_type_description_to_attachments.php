<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional uploader-supplied metadata on attachments: a free-text `type`
 * (an org-scoped vocabulary like "Sign-in sheet", "Test" — not the MIME type)
 * and a freeform `description`. Both shown in the attachments list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('type')->nullable()->after('filename');
            $table->text('description')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['type', 'description']);
        });
    }
};
