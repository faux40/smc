<?php

use App\Models\Training;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `rqmt_elements.name` becomes a nullable OVERRIDE: null means "display the
 * module's live name". Every existing value was a snapshot taken at attach
 * time (never a deliberate label), and a training rename left those snapshots
 * pointing at names that no longer exist — so they are all nulled, not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rqmt_elements', function (Blueprint $table): void {
            $table->string('name')->nullable()->change();
        });

        DB::table('rqmt_elements')->update(['name' => null]);
    }

    public function down(): void
    {
        // Re-freeze the live module name into every row before restoring
        // NOT NULL. module_id is varchar while trainings.id is uuid on
        // Postgres, hence the cast; SQLite compares loosely.
        $cast = DB::connection()->getDriverName() === 'pgsql' ? '::text' : '';

        DB::statement(
            "update rqmt_elements set name = (
                select name from trainings where trainings.id{$cast} = rqmt_elements.module_id
            ) where name is null and module_type = ?",
            [Training::class],
        );

        DB::table('rqmt_elements')->whereNull('name')->update(['name' => '']);

        Schema::table('rqmt_elements', function (Blueprint $table): void {
            $table->string('name')->nullable(false)->change();
        });
    }
};
