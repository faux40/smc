<?php

namespace Tests\Feature\Settings;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 15.5 — `/settings/notifications` page + update endpoint. The
 * page renders the full type × channel matrix (absent rows default to
 * enabled); `update()` upserts every cell for the authenticated user.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, all-enabled matrix payload for the update endpoint.
     *
     * @return array<string, array<string, bool>>
     */
    private function fullMatrix(bool $value = true): array
    {
        $matrix = [];
        foreach (NotificationPreference::TYPES as $type) {
            foreach (NotificationPreference::CHANNELS as $channel) {
                $matrix[$type][$channel] = $value;
            }
        }

        return $matrix;
    }

    public function test_edit_page_renders_with_a_full_default_matrix(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Notifications')
                ->has('preferences')
                ->has('mailEnabled')
                // Every type present, every channel defaulting to true.
                ->where('preferences.assignment_created.inapp', true)
                ->where('preferences.assignment_created.mail', true)
                ->where('preferences.assignment_overdue.inapp', true)
                ->where('preferences.assignment_overdue.mail', true)
            );
    }

    public function test_edit_page_reflects_saved_preferences(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_due_soon',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preferences.assignment_due_soon.mail', false)
                // Untouched cells still default to true.
                ->where('preferences.assignment_due_soon.inapp', true)
            );
    }

    public function test_mail_enabled_prop_reflects_config(): void
    {
        $user = User::factory()->create();

        config(['notifications.mail_enabled' => true]);
        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mailEnabled', true));

        config(['notifications.mail_enabled' => false]);
        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mailEnabled', false));
    }

    public function test_edit_page_redirects_guest(): void
    {
        $this->get(route('notification-preferences.edit'))->assertRedirect(route('login'));
    }

    public function test_update_persists_the_matrix(): void
    {
        $user = User::factory()->create();
        $matrix = $this->fullMatrix(true);
        $matrix['assignment_overdue']['mail'] = false;

        $this->actingAs($user)
            ->patch(route('notification-preferences.update'), ['preferences' => $matrix])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('notification-preferences.edit'));

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'assignment_overdue',
            'channel' => 'mail',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'type' => 'assignment_created',
            'channel' => 'inapp',
            'enabled' => true,
        ]);
        // 4 types × 2 channels, all upserted.
        $this->assertSame(8, NotificationPreference::where('user_id', $user->id)->count());
    }

    public function test_update_upserts_without_duplicating(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create([
            'user_id' => $user->id,
            'type' => 'assignment_due_soon',
            'channel' => 'mail',
            'enabled' => true,
        ]);

        $matrix = $this->fullMatrix(true);
        $matrix['assignment_due_soon']['mail'] = false;

        $this->actingAs($user)
            ->patch(route('notification-preferences.update'), ['preferences' => $matrix])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            NotificationPreference::where('user_id', $user->id)
                ->where('type', 'assignment_due_soon')
                ->where('channel', 'mail')
                ->count(),
        );
        $this->assertFalse(
            NotificationPreference::allows($user, 'assignment_due_soon', 'mail'),
        );
    }

    public function test_update_rejects_an_incomplete_matrix(): void
    {
        $user = User::factory()->create();
        $matrix = $this->fullMatrix(true);
        unset($matrix['assignment_overdue']['mail']);

        $this->actingAs($user)
            ->patch(route('notification-preferences.update'), ['preferences' => $matrix])
            ->assertSessionHasErrors('preferences.assignment_overdue.mail');
    }

    public function test_update_rejects_non_boolean_values(): void
    {
        $user = User::factory()->create();
        $matrix = $this->fullMatrix(true);
        $matrix['assignment_created']['inapp'] = 'yes';

        $this->actingAs($user)
            ->patch(route('notification-preferences.update'), ['preferences' => $matrix])
            ->assertSessionHasErrors('preferences.assignment_created.inapp');
    }

    public function test_update_only_touches_the_actors_own_preferences(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($actor)
            ->patch(route('notification-preferences.update'), ['preferences' => $this->fullMatrix(false)])
            ->assertSessionHasNoErrors();

        $this->assertSame(8, NotificationPreference::where('user_id', $actor->id)->count());
        $this->assertSame(0, NotificationPreference::where('user_id', $other->id)->count());
    }
}
