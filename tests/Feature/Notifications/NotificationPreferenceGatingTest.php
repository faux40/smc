<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 15.5 — `ChannelsWithGatedMail::via()` consults
 * `NotificationPreference` beneath the deployment-level mail flag.
 *
 *   - Absent preference rows → all-on default.
 *   - An `inapp` opt-out drops both `database` and `broadcast`.
 *   - A `mail` opt-out drops `mail` (which is still independently
 *     gated by `notifications.mail_enabled`).
 *   - Preferences are per-type — opting out of one notification type
 *     leaves the others untouched.
 */
class NotificationPreferenceGatingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->for($this->org, 'organization')->create();
    }

    private function optOut(string $type, string $channel): void
    {
        NotificationPreference::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'channel' => $channel,
            'enabled' => false,
        ]);
    }

    private function dueSoonVia(): array
    {
        return (new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30))->via($this->user);
    }

    public function test_default_with_no_rows_is_inapp_only_when_mail_flag_off(): void
    {
        config(['notifications.mail_enabled' => false]);

        $this->assertSame(['database', 'broadcast'], $this->dueSoonVia());
    }

    public function test_default_with_no_rows_includes_mail_when_flag_on(): void
    {
        config(['notifications.mail_enabled' => true]);

        $via = $this->dueSoonVia();
        $this->assertContains('database', $via);
        $this->assertContains('broadcast', $via);
        $this->assertContains('mail', $via);
    }

    public function test_inapp_opt_out_drops_database_and_broadcast(): void
    {
        config(['notifications.mail_enabled' => false]);
        $this->optOut('assignment_due_soon', 'inapp');

        $this->assertSame([], $this->dueSoonVia());
    }

    public function test_inapp_opt_out_leaves_mail_when_flag_on(): void
    {
        config(['notifications.mail_enabled' => true]);
        $this->optOut('assignment_due_soon', 'inapp');

        $this->assertSame(['mail'], $this->dueSoonVia());
    }

    public function test_mail_opt_out_drops_only_mail(): void
    {
        config(['notifications.mail_enabled' => true]);
        $this->optOut('assignment_due_soon', 'mail');

        $via = $this->dueSoonVia();
        $this->assertContains('database', $via);
        $this->assertContains('broadcast', $via);
        $this->assertNotContains('mail', $via);
    }

    public function test_preferences_are_scoped_per_type(): void
    {
        config(['notifications.mail_enabled' => true]);
        // Opt out of due-soon entirely; overdue must be unaffected.
        $this->optOut('assignment_due_soon', 'inapp');
        $this->optOut('assignment_due_soon', 'mail');

        $this->assertSame([], $this->dueSoonVia());

        $overdueVia = (new AssignmentOverdue('ta-1', 'tr-1', 'Fall Protection', '2026-04-01', -10))->via($this->user);
        $this->assertContains('database', $overdueVia);
        $this->assertContains('broadcast', $overdueVia);
        $this->assertContains('mail', $overdueVia);
    }
}
