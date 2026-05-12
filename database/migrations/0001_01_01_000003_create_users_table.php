<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // org_id is nullable only to support the new-user-creates-new-org
            // transaction (User row inserted first, then org_id back-filled
            // inside the same DB transaction). Application policy is "always
            // set" — the BelongsToOrganization scope assumes non-null.
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            // Name split into 5 fields: f_name + l_name required, the rest
            // optional. The User model exposes a `name` accessor that
            // composes them for display.
            $table->string('f_name');
            $table->string('m_name')->nullable();
            $table->string('l_name');
            $table->string('prefix_name')->nullable();
            $table->string('suffix_name')->nullable();
            // Email + password both nullable: no-login users (frontline workers
            // managed by an admin) have neither.
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            // Fortify 2FA columns inline — no separate migration.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // active / disabled — admin-disable without delete.
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        // Email uniqueness is global but only among non-soft-deleted rows.
        // Raw DB::statement keeps it DB-agnostic — Schema::dropUnique emits
        // DROP CONSTRAINT under Postgres and would fail since this isn't one.
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
