<?php

namespace Tests\Feature\Notifications;

use App\Models\Completion;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AssignmentCreatedForYou;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentOverdue;
use App\Notifications\CompletionRecordedForYou;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Phase 15.4 — `mail` channel coverage. The channel is gated by the
 * `notifications.mail_enabled` flag (off by default) and by the
 * recipient actually having an email, both enforced by the shared
 * `ChannelsWithGatedMail` trait. Each notification also gains a
 * `toMail()` and becomes fully queued (`ShouldQueue`).
 */
class MailChannelTest extends TestCase
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

    private function completion(): Completion
    {
        return Completion::factory()
            ->for($this->org, 'organization')
            ->for($this->user, 'user')
            ->create();
    }

    /**
     * One instance of every notification class, constructed minimally.
     *
     * @return array<int, Notification>
     */
    private function allNotifications(): array
    {
        return [
            new AssignmentCreatedForYou('Fall Protection', 'tr-1', null),
            new CompletionRecordedForYou($this->completion()),
            new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30),
            new AssignmentOverdue('ta-1', 'tr-1', 'Fall Protection', '2026-04-01', -20),
        ];
    }

    public function test_via_omits_mail_when_flag_disabled(): void
    {
        config(['notifications.mail_enabled' => false]);

        $via = (new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30))->via($this->user);

        $this->assertSame(['database', 'broadcast'], $via);
    }

    public function test_via_includes_mail_when_flag_enabled_and_recipient_has_email(): void
    {
        config(['notifications.mail_enabled' => true]);

        $via = (new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30))->via($this->user);

        $this->assertContains('mail', $via);
        $this->assertContains('database', $via);
        $this->assertContains('broadcast', $via);
    }

    public function test_via_omits_mail_for_no_login_user_even_when_flag_enabled(): void
    {
        config(['notifications.mail_enabled' => true]);

        $noLogin = User::factory()->for($this->org, 'organization')->noLogin()->create();
        $this->assertNull($noLogin->email);

        $via = (new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30))->via($noLogin);

        $this->assertNotContains('mail', $via);
        $this->assertSame(['database', 'broadcast'], $via);
    }

    public function test_all_four_notifications_gate_mail_through_the_shared_trait(): void
    {
        config(['notifications.mail_enabled' => true]);

        foreach ($this->allNotifications() as $notification) {
            $this->assertContains(
                'mail',
                $notification->via($this->user),
                $notification::class.' should include the mail channel when the flag is on',
            );
        }

        config(['notifications.mail_enabled' => false]);

        foreach ($this->allNotifications() as $notification) {
            $this->assertNotContains(
                'mail',
                $notification->via($this->user),
                $notification::class.' must not include the mail channel when the flag is off',
            );
        }
    }

    public function test_all_four_notifications_are_queued(): void
    {
        foreach ($this->allNotifications() as $notification) {
            $this->assertInstanceOf(
                ShouldQueue::class,
                $notification,
                $notification::class.' should be queued (ShouldQueue)',
            );
        }
    }

    public function test_assignment_created_to_mail(): void
    {
        $mail = (new AssignmentCreatedForYou('Fall Protection', 'tr-1', null))->toMail($this->user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('Fall Protection', $mail->subject);
        $this->assertSame(route('users.show', $this->user), $mail->actionUrl);
    }

    public function test_completion_recorded_to_mail(): void
    {
        $mail = (new CompletionRecordedForYou($this->completion()))->toMail($this->user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsStringIgnoringCase('completion', $mail->subject);
        $this->assertSame(route('users.show', $this->user), $mail->actionUrl);
    }

    public function test_assignment_due_soon_to_mail(): void
    {
        $mail = (new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30))->toMail($this->user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsStringIgnoringCase('due', $mail->subject);
        $this->assertStringContainsString('Fall Protection', $mail->subject);
        $bodyText = implode(' ', $mail->introLines);
        $this->assertStringContainsString('2026-07-01', $bodyText);
    }

    public function test_assignment_overdue_to_mail(): void
    {
        $mail = (new AssignmentOverdue('ta-1', 'tr-1', 'Fall Protection', '2026-04-01', -20))->toMail($this->user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsStringIgnoringCase('overdue', $mail->subject);
        $this->assertStringContainsString('Fall Protection', $mail->subject);
        $bodyText = implode(' ', $mail->introLines);
        $this->assertStringContainsString('20', $bodyText);
    }

    public function test_mail_channel_active_end_to_end_when_enabled(): void
    {
        config(['notifications.mail_enabled' => true]);
        NotificationFacade::fake();

        $this->user->notify(new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30));

        NotificationFacade::assertSentTo(
            $this->user,
            AssignmentDueSoon::class,
            function ($notification, $channels) {
                return in_array('mail', $channels, true);
            },
        );
    }

    public function test_mail_channel_absent_end_to_end_when_disabled(): void
    {
        config(['notifications.mail_enabled' => false]);
        NotificationFacade::fake();

        $this->user->notify(new AssignmentDueSoon('ta-1', 'tr-1', 'Fall Protection', '2026-07-01', 30));

        NotificationFacade::assertSentTo(
            $this->user,
            AssignmentDueSoon::class,
            function ($notification, $channels) {
                return ! in_array('mail', $channels, true);
            },
        );
    }
}
