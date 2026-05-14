<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ManagerComplianceDigest;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Phase 15.6 — the weekly manager digest notification: payload shape,
 * mail rendering, and that it flows through the same preference gate
 * as the other per-user notifications.
 */
class ManagerComplianceDigestTest extends TestCase
{
    use RefreshDatabase;

    private function digest(): ManagerComplianceDigest
    {
        return new ManagerComplianceDigest(
            orgName: 'Acme Co',
            summary: [
                'counts' => [
                    'overdue' => 3,
                    'due_soon' => 2,
                    'current' => 10,
                    'never_started' => 1,
                    'inactive' => 0,
                ],
                'total_assignments' => 16,
                'total_users' => 8,
                'users_with_overdue' => 2,
            ],
            topOverdue: [
                ['user_id' => 'u1', 'name' => 'Jane Doe', 'email' => 'jane@x.test', 'overdue_count' => 2],
            ],
            topDueSoon: [
                [
                    'user_id' => 'u2',
                    'user_name' => 'John Roe',
                    'requirement_name' => 'Fall Protection',
                    'next_due_date' => '2026-06-01',
                    'days_until_due' => 18,
                ],
            ],
        );
    }

    public function test_to_array_payload_shape(): void
    {
        $user = User::factory()->create();
        $payload = $this->digest()->toArray($user);

        $this->assertSame('manager_digest', $payload['kind']);
        $this->assertSame('Acme Co', $payload['org_name']);
        $this->assertSame(3, $payload['summary']['counts']['overdue']);
        $this->assertCount(1, $payload['top_overdue']);
        $this->assertSame('Jane Doe', $payload['top_overdue'][0]['name']);
        $this->assertCount(1, $payload['top_due_soon']);
        $this->assertSame('Fall Protection', $payload['top_due_soon'][0]['requirement_name']);
    }

    public function test_to_mail_renders_org_counts_and_rollups(): void
    {
        $user = User::factory()->create();
        $mail = $this->digest()->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('Acme Co', $mail->subject);

        $body = implode(' ', $mail->introLines);
        $this->assertStringContainsString('Overdue: 3', $body);
        $this->assertStringContainsString('Jane Doe', $body);
        $this->assertStringContainsString('Fall Protection', $body);
        $this->assertSame(route('dashboard'), $mail->actionUrl);
    }

    public function test_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, $this->digest());
    }

    public function test_respects_the_manager_digest_preference(): void
    {
        $this->seed(RoleSeeder::class);
        $org = Organization::factory()->create();
        $user = User::factory()->for($org, 'organization')->withRole('Manager')->create();

        config(['notifications.mail_enabled' => true]);

        // Default — no rows — delivers on both logical channels.
        $this->assertSame(['database', 'broadcast', 'mail'], $this->digest()->via($user));

        // Opt out of both channels for this type.
        foreach (['inapp', 'mail'] as $channel) {
            NotificationPreference::create([
                'user_id' => $user->id,
                'type' => 'manager_digest',
                'channel' => $channel,
                'enabled' => false,
            ]);
        }

        $this->assertSame([], $this->digest()->via($user));
    }
}
