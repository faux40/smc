<?php

namespace App\Notifications;

use App\Notifications\Concerns\ChannelsWithGatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 15.6 — weekly compliance rollup for an org's Owner / SuperAdmin
 * / Admin / Manager users. Dispatched by `digests:send-manager-compliance`
 * once per week at Monday 08:00 in the org's timezone.
 *
 * The payload is computed once per org and fanned out, so the
 * constructor takes plain arrays (queue-serializable — no models). It
 * reuses `UserComplianceCalculator::summarizeOrg()` for the headline
 * counts plus `topOverdueUsers()` / `topDueSoon()` for the rollups.
 *
 * Preference-gated like the other per-user notifications (`TYPE` =
 * `manager_digest`); the preferences UI only surfaces the toggle to
 * manager+ roles since no one else receives it.
 */
class ManagerComplianceDigest extends Notification implements ShouldBroadcast, ShouldQueue
{
    use ChannelsWithGatedMail, Queueable;

    /** Preference key — see NotificationPreference::TYPES. */
    public const TYPE = 'manager_digest';

    /**
     * @param  array<string, mixed>  $summary  summarizeOrg() output
     * @param  array<int, array<string, mixed>>  $topOverdue
     * @param  array<int, array<string, mixed>>  $topDueSoon
     */
    public function __construct(
        public readonly string $orgName,
        public readonly array $summary,
        public readonly array $topOverdue,
        public readonly array $topDueSoon,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::TYPE,
            'org_name' => $this->orgName,
            'summary' => $this->summary,
            'top_overdue' => $this->topOverdue,
            'top_due_soon' => $this->topDueSoon,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $counts = $this->summary['counts'];

        $mail = (new MailMessage)
            ->subject('Weekly compliance digest — '.$this->orgName)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Here is this week\'s compliance summary for '.$this->orgName.'.')
            ->line(sprintf(
                'Overdue: %d · Due soon: %d · Current: %d',
                $counts['overdue'],
                $counts['due_soon'],
                $counts['current'],
            ))
            ->line(sprintf(
                '%d of %d users have at least one overdue requirement.',
                $this->summary['users_with_overdue'],
                $this->summary['total_users'],
            ));

        if ($this->topOverdue !== []) {
            $mail->line('Top overdue:');
            foreach ($this->topOverdue as $row) {
                $mail->line(sprintf('- %s — %d overdue', $row['name'], $row['overdue_count']));
            }
        }

        if ($this->topDueSoon !== []) {
            $mail->line('Coming due soon:');
            foreach ($this->topDueSoon as $row) {
                $mail->line(sprintf(
                    '- %s — %s (due %s)',
                    $row['user_name'],
                    $row['requirement_name'],
                    $row['next_due_date'] ?? 'soon',
                ));
            }
        }

        return $mail
            ->action('Open the dashboard', route('dashboard'))
            ->line('Review the dashboard for the full picture.');
    }
}
