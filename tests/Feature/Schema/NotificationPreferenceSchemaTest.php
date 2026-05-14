<?php

namespace Tests\Feature\Schema;

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationPreferenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('notification_preferences', [
            'id', 'user_id', 'type', 'channel', 'enabled',
            'created_at', 'updated_at',
        ]));
    }

    public function test_user_type_channel_is_unique(): void
    {
        $user = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_due_soon',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->expectException(QueryException::class);

        NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_due_soon',
            'channel' => 'mail',
            'enabled' => true,
        ]);
    }

    public function test_cascade_deletes_with_user(): void
    {
        $user = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        $pref = NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_overdue',
            'channel' => 'inapp',
            'enabled' => false,
        ]);

        $user->forceDelete();

        $this->assertDatabaseMissing('notification_preferences', ['id' => $pref->id]);
    }

    public function test_allows_defaults_true_when_no_row(): void
    {
        $user = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        $this->assertTrue(NotificationPreference::allows($user, 'assignment_due_soon', 'mail'));
    }

    public function test_allows_reflects_a_disabled_row(): void
    {
        $user = User::factory()->for(Organization::factory()->create(), 'organization')->create();

        NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_due_soon',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->assertFalse(NotificationPreference::allows($user, 'assignment_due_soon', 'mail'));
        // A different channel for the same type is untouched.
        $this->assertTrue(NotificationPreference::allows($user, 'assignment_due_soon', 'inapp'));
    }
}
