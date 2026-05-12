<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's standard notifications table, adapted for our UUID
     * primary keys. Backs the database channel (Phase 15.1+) and the
     * in-app inbox UI (15.2). Each row is per-(notifiable, event)
     * with a polymorphic `notifiable` so future non-User recipients
     * are possible without a schema change.
     *
     * `read_at` flips when the user acknowledges in the inbox.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // FQCN of the App\Notifications\* class for filtering / display.
            $table->string('type');
            // notifiable_type + notifiable_id; uuidMorphs since our users
            // are UUID-keyed (and any future poly target will be too).
            $table->uuidMorphs('notifiable');
            // JSON payload set by each notification's toArray() output.
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Inbox queries typically filter "my unread" → composite on
            // the notifiable side gives us those rows cheaply.
            $table->index(['notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
