<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable on create — the new-user-creates-new-org transaction
            // sets it immediately after, but the User row needs to exist first.
            // Constrained FK added by the users redesign migration so the FK
            // arrow points at a real table.
            $table->uuid('owner_user_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
