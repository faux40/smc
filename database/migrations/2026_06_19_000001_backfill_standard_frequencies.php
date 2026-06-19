<?php

use App\Actions\BackfillStandardFrequencies;
use Illuminate\Database\Migrations\Migration;

/**
 * Add the standard frequency set (incl. the new 2/3/4/5-year options) to every
 * existing organization. New orgs already get the full set at registration;
 * this catches orgs created before those options existed. Idempotent and a
 * no-op on a fresh database (no orgs yet) — orgs are created later via
 * registration, which seeds the current set.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new BackfillStandardFrequencies)->handle();
    }

    public function down(): void
    {
        // No-op: this only adds standard rows; we don't delete org data on rollback.
    }
};
