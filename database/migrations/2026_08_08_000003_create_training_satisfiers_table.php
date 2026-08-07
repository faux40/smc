<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hierarchy pointer grows OR-semantics: a lower training may be satisfied
 * by ANY of several higher ones (John's case: Comp Person Initial OR Refresher
 * each satisfy Authorized). `trainings.superseded_by_id` (single parent)
 * becomes the `training_satisfiers` edge table (child → N parents, a DAG:
 * diamonds legal, cycles refused at the write side).
 *
 * The engine needs no semantic change — best-effective-expiry-wins already
 * weighs N covering candidates; only where the N comes from changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_satisfiers', function (Blueprint $table): void {
            $table->uuid('org_id')->index();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignUuid('satisfied_by_id')->constrained('trainings')->cascadeOnDelete();
            $table->primary(['training_id', 'satisfied_by_id']);
            $table->index('satisfied_by_id');
        });

        // Carry every existing single pointer over as a one-edge set —
        // soft-deleted trainings included, since chains hop through them.
        DB::statement(
            'insert into training_satisfiers (org_id, training_id, satisfied_by_id)
             select org_id, id, superseded_by_id from trainings where superseded_by_id is not null',
        );

        Schema::table('trainings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('trainings', function (Blueprint $table): void {
            $table->foreignUuid('superseded_by_id')
                ->nullable()
                ->constrained('trainings')
                ->nullOnDelete();
        });

        // Lossy on purpose: a multi-parent set can't fold into one pointer.
        // Keep the first edge per child (arbitrary but deterministic).
        $edges = DB::table('training_satisfiers')
            ->orderBy('training_id')
            ->orderBy('satisfied_by_id')
            ->get()
            ->unique('training_id');

        foreach ($edges as $edge) {
            DB::table('trainings')
                ->where('id', $edge->training_id)
                ->update(['superseded_by_id' => $edge->satisfied_by_id]);
        }

        Schema::dropIfExists('training_satisfiers');
    }
};
