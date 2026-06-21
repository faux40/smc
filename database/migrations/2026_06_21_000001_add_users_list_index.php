<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index backing the paginated users list: the default query filters
 * by org_id + status and orders by (l_name, f_name), so this covers the common
 * "active users, sorted by name, one org" page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['org_id', 'status', 'l_name', 'f_name'], 'users_org_status_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_org_status_name_idx');
        });
    }
};
